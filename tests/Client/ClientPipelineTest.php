<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Tests\Client;

use PHPUnit\Framework\Attributes\{
    CoversClass,
    DataProvider
};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smithy\Async\{
    Async,
    EventLoopInterface
};
use Smithy\Client\{
    ClientConfig,
    ClientPipeline,
    RequestOverrideConfig
};
use Smithy\Client\Auth\{
    AnonymousIdentity,
    AuthSchemeInterface,
    AuthSchemeResolverInterface,
    IdentityInterface,
    IdentityNotFoundException,
    IdentityResolverInterface,
    IdentityResult,
    ResolvedAuthScheme,
    SignerInterface,
    SignerResult,
    StaticAuthSchemeResolver
};
use Smithy\Client\Endpoint\{
    EndpointResolverInterface,
    StaticEndpointResolver
};
use Smithy\Client\Interceptor\{
    AbstractInterceptor,
    HttpChecksumInterceptor,
    InputContext,
    InterceptorChain,
    OutputContext,
    RequestContext
};
use Smithy\Client\Retry\{
    NoRetryStrategy,
    RetryState,
    StandardRetryStrategy,
    RetryRequestFlagResolverInterface,
    RetryStrategyInterface,
    TokenAcquisitionFailedException
};
use Smithy\Core\Endpoint\{
    Endpoint,
    EndpointAuthScheme
};
use Smithy\Core\Transport\{
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Core\Serde\{
    Schema,
    SerializableStructInterface
};
use Smithy\Core\Shape\OperationInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Http\Client\Exception\NetworkException;
use Smithy\Http\Client\{
    HttpRequestOptions,
    RequestTrailers
};
use Smithy\Http\Message\{
    HttpMessageExchange,
    HttpTransportRequest,
    HttpTransportResponse,
    Request,
    RequestStreamInterface,
    Response,
    Uri
};
use Smithy\Protocol\ProtocolInterface;
use Smithy\Tests\Async\Utils\RecordingEventLoopFixture;
use Smithy\Tests\Client\Auth\Utils\NotFoundResolverFixture;
use Smithy\Tests\Client\Fake\{
    FakeInput,
    FakeOperation,
    FakeOutput,
    FakeProtocol,
    RecordingInterceptor,
    TestAuthSchemeFixture
};
use Smithy\Tests\Http\Client\Curl\Utils\TestStreamFixture;
use Smithy\Tests\Utils\Http\GenericPsrRequest;
use Smithy\Tests\Utils\Http\MockHttpClient;

#[CoversClass(ClientPipeline::class)]
final class ClientPipelineTest extends TestCase
{
    private EventLoopInterface $previousEventLoop;

    protected function setUp(): void
    {
        $this->previousEventLoop = Async::eventLoop();
    }

    protected function tearDown(): void
    {
        // Restore the global loop so a fake loop never leaks into later tests.
        Async::configure($this->previousEventLoop);
    }

    public function testHappyPathReturnsOutputFromProtocol(): void
    {
        [$pipeline, $http] = $this->buildPipeline();

        $out = $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput('hello')
        );

        $this->assertInstanceOf(FakeOutput::class, $out);
        $this->assertSame('server-body', $out->value);
        $this->assertCount(1, $http->sentRequests);
        $this->assertSame(
            'input:hello',
            (string) $http->sentRequests[0]->getBody()
        );
    }

    public function testSerializedRequestIsBoundToEndpointBeforeTransport(): void
    {
        $serializedRequest = null;
        $observer = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['readAfterSerialization'])
            ->getMock();
        $observer->method('readAfterSerialization')->willReturnCallback(
            static function (RequestContext $ctx) use (
                &$serializedRequest
            ): void {
                $serializedRequest = $ctx->request;
            }
        );
        [$pipeline, $http] = $this->buildPipeline([$observer]);

        $pipeline->execute(FakeOperation::instance(), new FakeInput('hello'));

        $this->assertInstanceOf(Request::class, $http->sentRequests[0]);
        $this->assertNotSame(
            $serializedRequest,
            $http->sentRequests[0]
        );
        $serializedRequest = HttpTransportRequest::expect(
            $serializedRequest
        )->request;
        $this->assertSame('/', (string) $serializedRequest->getUri());
        $this->assertSame(
            'https://example.test/',
            (string) $http->sentRequests[0]->getUri()
        );
        $this->assertSame(
            (string) $serializedRequest->getBody(),
            (string) $http->sentRequests[0]->getBody()
        );
    }

    public function testSkipsInterceptorsThatDoNotApplyToOperation(): void
    {
        $interceptor = new class extends AbstractInterceptor {
            public function appliesTo(OperationInterface $operation): bool
            {
                return false;
            }

            public function readBeforeExecution(InputContext $ctx): void
            {
                throw new \LogicException('Hook must not run.');
            }
        };
        [$pipeline, $http] = $this->buildPipeline(
            interceptors: [$interceptor]
        );

        $out = $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput('hello')
        );

        $this->assertSame('server-body', $out->value);
        $this->assertSame(1, $http->sendCallCount);
    }

    public function testRequestOverridesReachTransportWithoutInterceptors(): void
    {
        [$pipeline, $http] = $this->buildPipeline();
        $overrides = RequestOverrideConfig::fromArray([
            '@http' => [
                'timeout' => 2.5,
                'headers' => [
                    'x-test-request' => ['one', 'two']
                ]
            ]
        ]);

        $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput('hello'),
            $overrides
        );

        $this->assertSame(
            ['one', 'two'],
            $http->sentRequests[0]->getHeader('x-test-request')
        );
        $this->assertSame(2.5, $http->sentOptions[0]->timeout);
    }

    public function testRequestOverridesAreVisibleBeforeAttempt(): void
    {
        $seen = [];
        $observer = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods([
                'readAfterSerialization',
                'modifyBeforeRetryLoop',
                'readBeforeAttempt'
            ])
            ->getMock();
        $observer->method('readAfterSerialization')->willReturnCallback(
            static function (RequestContext $ctx) use (&$seen): void {
                $seen['readAfterSerialization'] = HttpTransportRequest::expect(
                    $ctx->request
                )->request->getHeaderLine('x-test-request');
            }
        );
        $observer->method('modifyBeforeRetryLoop')->willReturnCallback(
            static function (
                RequestContext $ctx
            ) use (&$seen): TransportRequestInterface {
                $seen['modifyBeforeRetryLoop'] = HttpTransportRequest::expect(
                    $ctx->request
                )->request->getHeaderLine('x-test-request');

                return $ctx->request;
            }
        );
        $observer->method('readBeforeAttempt')->willReturnCallback(
            static function (RequestContext $ctx) use (&$seen): void {
                $seen['readBeforeAttempt'] = HttpTransportRequest::expect(
                    $ctx->request
                )->request->getHeaderLine('x-test-request');
            }
        );
        [$pipeline] = $this->buildPipeline([$observer]);

        $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput('hello'),
            RequestOverrideConfig::fromArray([
                '@http' => [
                    'headers' => ['x-test-request' => 'override']
                ]
            ])
        );

        $this->assertSame([
            'readAfterSerialization' => 'override',
            'modifyBeforeRetryLoop' => 'override',
            'readBeforeAttempt' => 'override'
        ], $seen);
    }

    public function testPartialHttpOverridesUseTransportDefaults(): void
    {
        $http = (new MockHttpClient())
            ->withDefaultRequestOptions(new HttpRequestOptions(
                timeout: 9.0,
                proxy: 'http://proxy.example',
                readTimeout: 8.0
            ))
            ->willReturn('server-body');
        [$pipeline] = $this->buildPipeline(http: $http);

        $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput(),
            RequestOverrideConfig::fromArray([
                '@http' => ['timeout' => 2.0]
            ])
        );

        $this->assertSame(2.0, $http->sentOptions[0]->timeout);
        $this->assertSame(
            'http://proxy.example',
            $http->sentOptions[0]->proxy
        );
        $this->assertSame(8.0, $http->sentOptions[0]->readTimeout);
    }

    public function testInvalidHttpOverrideRunsExecutionLifecycle(): void
    {
        $recorder = new RecordingInterceptor();
        [$pipeline, $http] = $this->buildPipeline([$recorder]);
        $overrides = RequestOverrideConfig::fromArray([
            '@http' => ['timeout' => 'invalid']
        ]);

        try {
            $pipeline->execute(
                FakeOperation::instance(),
                new FakeInput(),
                $overrides
            );
            $this->fail('Expected HTTP override resolution to fail.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame(
                '@http.timeout must be numeric.',
                $error->getMessage()
            );
        }

        $this->assertSame([
            'readBeforeExecution',
            'modifyBeforeCompletion',
            'readAfterExecution'
        ], $recorder->calls);
        $this->assertSame(0, $http->sendCallCount);
    }

    public function testHookOrderOnSuccess(): void
    {
        $rec = new RecordingInterceptor();
        [$pipeline] = $this->buildPipeline([$rec]);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame([
            'readBeforeExecution',
            'modifyBeforeSerialization',
            'readBeforeSerialization',
            'readAfterSerialization',
            'modifyBeforeRetryLoop',
            'readBeforeAttempt',
            'modifyBeforeSigning',
            'readBeforeSigning',
            'readAfterSigning',
            'modifyBeforeTransmit',
            'readBeforeTransmit',
            'readAfterTransmit',
            'modifyBeforeDeserialization',
            'readBeforeDeserialization',
            'readAfterDeserialization',
            'modifyBeforeAttemptCompletion',
            'readAfterAttempt',
            'modifyBeforeCompletion',
            'readAfterExecution'
        ], $rec->calls);
    }

    public function testMultipleInterceptorsRunPerHookInOrder(): void
    {
        $a = new RecordingInterceptor('A');
        $b = new RecordingInterceptor('B');
        [$pipeline] = $this->buildPipeline([$a, $b]);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        // Both interceptors record readBeforeExecution before either
        // records modifyBeforeSerialization.
        $combined = [];
        for ($i = 0; $i < \count($a->calls); $i++) {
            $combined[] = $a->calls[$i];
            $combined[] = $b->calls[$i];
        }

        $this->assertSame('A:readBeforeExecution', $combined[0]);
        $this->assertSame('B:readBeforeExecution', $combined[1]);
        $this->assertSame('A:modifyBeforeSerialization', $combined[2]);
        $this->assertSame('B:modifyBeforeSerialization', $combined[3]);
    }

    public function testModifyBeforeSerializationReplacesInput(): void
    {
        $swap = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['modifyBeforeSerialization'])
            ->getMock();
        $swap->method('modifyBeforeSerialization')
            ->willReturn(new FakeInput('swapped'));

        [$pipeline, $http] = $this->buildPipeline([$swap]);

        $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput('original')
        );

        $this->assertSame(
            'input:swapped',
            (string) $http->sentRequests[0]->getBody()
        );
    }

    public function testModifyBeforeTransmitReplacesRequest(): void
    {
        $swap = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['modifyBeforeTransmit'])
            ->getMock();
        $swap->method('modifyBeforeTransmit')->willReturnCallback(
            static function (
                RequestContext $ctx
            ): TransportRequestInterface {
                $request = HttpTransportRequest::expect($ctx->request);

                return $request->withRequest(
                    $request->request->withHeader('x-swapped', '1')
                );
            }
        );

        [$pipeline, $http] = $this->buildPipeline([$swap]);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(
            '1',
            $http->sentRequests[0]->getHeaderLine('x-swapped')
        );
    }

    public function testEndpointHeadersAreMergedIntoRequest(): void
    {
        $http = (new MockHttpClient())->willReturn('body');
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint(
                    'https://example.test',
                    headers: ['x-endpoint' => 'yes']
                )
            )
        );
        $pipeline = new ClientPipeline($config);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(
            'yes',
            $http->sentRequests[0]->getHeaderLine('x-endpoint')
        );
    }

    public function testEndpointHeadersPreserveAndOverwriteSmithyHeaders(): void
    {
        $http = (new MockHttpClient())->willReturn('body');
        $protocol = $this->createMock(ProtocolInterface::class);
        $protocol->method('getMessageExchange')->willReturn(
            HttpMessageExchange::instance()
        );
        $protocol->method('serializeRequest')->willReturnCallback(
            static fn(
                OperationInterface $operation,
                SerializableStructInterface $input
            ): TransportRequestInterface => new HttpTransportRequest(
                new Request(
                    'POST',
                    new Uri('/'),
                    [
                        'X-Existing' => 'keep',
                        'X-Endpoint' => 'old'
                    ],
                    'input:custom'
                )
            )
        );
        $protocol->method('deserializeResponse')->willReturnCallback(
            static fn(
                OperationInterface $operation,
                TransportResponseInterface $response
            ): SerializableStructInterface =>
                new FakeOutput((string) HttpTransportResponse::expect(
                    $response
                )->response->getBody())
        );
        $protocol->method('setServiceEndpoint')->willReturnCallback(
            static fn(
                TransportRequestInterface $request,
                Endpoint $endpoint
            ): TransportRequestInterface => HttpTransportRequest::expect(
                $request
            )
                ->withRequest(
                    HttpTransportRequest::expect($request)
                        ->request
                        ->withUri(new Uri($endpoint->url))
                )
                ->withEndpointHeaders($endpoint)
        );

        $config = new ClientConfig(
            protocol: $protocol,
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test', headers: [
                    'x-endpoint' => 'new',
                    'x-added' => 'yes'
                ])
            )
        );
        $pipeline = new ClientPipeline($config);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $request = $http->sentRequests[0];
        $this->assertSame('keep', $request->getHeaderLine('x-existing'));
        $this->assertSame('new', $request->getHeaderLine('x-endpoint'));
        $this->assertSame('yes', $request->getHeaderLine('x-added'));
        $this->assertSame('input:custom', (string) $request->getBody());
    }

    public function testEndpointHeadersSupportGenericPsrRequest(): void
    {
        $http = (new MockHttpClient())->willReturn('body');
        $protocol = $this->createMock(ProtocolInterface::class);
        $protocol->method('getMessageExchange')->willReturn(
            HttpMessageExchange::instance()
        );
        $protocol->method('serializeRequest')->willReturnCallback(
            static fn(
                OperationInterface $operation,
                SerializableStructInterface $input
            ): TransportRequestInterface => new HttpTransportRequest(
                new GenericPsrRequest(
                    new Request(
                        'POST',
                        new Uri('/'),
                        ['X-Existing' => 'keep'],
                        'input:custom'
                    )
                )
            )
        );
        $protocol->method('deserializeResponse')->willReturnCallback(
            static fn(
                OperationInterface $operation,
                TransportResponseInterface $response
            ): SerializableStructInterface =>
                new FakeOutput((string) HttpTransportResponse::expect(
                    $response
                )->response->getBody())
        );
        $protocol->method('setServiceEndpoint')->willReturnCallback(
            static fn(
                TransportRequestInterface $request,
                Endpoint $endpoint
            ): TransportRequestInterface => HttpTransportRequest::expect(
                $request
            )
                ->withRequest(
                    HttpTransportRequest::expect($request)
                        ->request
                        ->withUri(new Uri($endpoint->url))
                )
                ->withEndpointHeaders($endpoint)
        );

        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: $protocol,
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test', headers: [
                    'X-Endpoint' => 'new'
                ])
            )
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $request = $http->sentRequests[0];
        $this->assertInstanceOf(GenericPsrRequest::class, $request);
        $this->assertSame('keep', $request->getHeaderLine('X-Existing'));
        $this->assertSame('new', $request->getHeaderLine('X-Endpoint'));
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('invalidEndpointHeaderProvider')]
    public function testRejectsInvalidEndpointHeaders(
        array $headers,
        string $message
    ): void
    {
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint(
                    'https://example.test',
                    headers: $headers
                )
            )
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidEndpointHeaderProvider(): iterable
    {
        yield 'invalid name' => [
            ['Bad Header' => 'value'],
            'HTTP header names must be valid tokens.',
        ];
        yield 'invalid value' => [
            ['X-Test' => "value\r\nX-Injected: yes"],
            'HTTP header values contain invalid control bytes.',
        ];
    }

    public function testEndpointAuthOverridesModeledAuthWithoutInterceptors(): void
    {
        $this->assertEndpointAuthOverridesModeledAuth([]);
    }

    public function testEndpointAuthOverridesModeledAuthWithInterceptors(): void
    {
        $this->assertEndpointAuthOverridesModeledAuth([
            new RecordingInterceptor()
        ]);
    }

    public function testUnsupportedEndpointAuthSchemeFailsBeforeTransmit(): void
    {
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint(
                    'https://example.test',
                    authSchemes: [
                        new EndpointAuthScheme('smithy.test#unsupported')
                    ]
                )
            )
        ));

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected unsupported endpoint auth to fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame(
                'Endpoint resolution requested unsupported auth schemes '
                . '[smithy.test#unsupported] for operation Fake.',
                $error->getMessage()
            );
        }

        $this->assertSame(0, $http->sendCallCount);
    }

    public function testClientPreferenceReordersModeledAuthSchemes(): void
    {
        $seen = (object) ['selectedScheme' => null];
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [
                $this->recordingAuthScheme('aws.auth#sigv4', $seen),
                $this->recordingAuthScheme(
                    'smithy.api#httpBearerAuth',
                    $seen
                )
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'aws.auth#sigv4',
                'smithy.api#httpBearerAuth'
            ]),
            authSchemePreference: ['httpBearerAuth']
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(
            'smithy.api#httpBearerAuth',
            $seen->selectedScheme
        );
    }

    public function testUnsupportedPreferenceLeavesModeledOrderIntact(): void
    {
        $seen = (object) ['selectedScheme' => null];
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [
                $this->recordingAuthScheme('aws.auth#sigv4', $seen)
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'aws.auth#sigv4',
                'smithy.api#httpBearerAuth'
            ]),
            authSchemePreference: ['sigv3']
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame('aws.auth#sigv4', $seen->selectedScheme);
    }

    public function testRequestPreferenceOverridesClientPreference(): void
    {
        $seen = (object) ['selectedScheme' => null];
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [
                $this->recordingAuthScheme('aws.auth#sigv4', $seen),
                $this->recordingAuthScheme(
                    'smithy.api#httpBearerAuth',
                    $seen
                )
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'aws.auth#sigv4',
                'smithy.api#httpBearerAuth'
            ]),
            authSchemePreference: ['sigv4']
        ));

        $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput(),
            RequestOverrideConfig::fromArray([
                '@auth' => ['httpBearerAuth']
            ])
        );

        $this->assertSame(
            'smithy.api#httpBearerAuth',
            $seen->selectedScheme
        );
    }

    public function testRequestPreferenceBypassesCachedAuthScheme(): void
    {
        $seen = (object) ['selectedScheme' => null];
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                (new MockHttpClient())->willReturn('body')
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [
                $this->recordingAuthScheme('aws.auth#sigv4', $seen),
                $this->recordingAuthScheme(
                    'smithy.api#httpBearerAuth',
                    $seen
                )
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'aws.auth#sigv4',
                'smithy.api#httpBearerAuth'
            ])
        ));
        $override = RequestOverrideConfig::fromArray([
            '@auth' => ['httpBearerAuth']
        ]);
        $selected = [];

        foreach ([null, $override, null] as $overrides) {
            $pipeline->execute(
                FakeOperation::instance(),
                new FakeInput(),
                $overrides
            );
            $selected[] = $seen->selectedScheme;
        }

        $this->assertSame([
            'aws.auth#sigv4',
            'smithy.api#httpBearerAuth',
            'aws.auth#sigv4'
        ], $selected);
    }

    public function testEndpointAuthRemainsAuthoritativeOverPreference(): void
    {
        $seen = (object) [
            'identityProperties' => null,
            'selectedScheme' => null,
            'signerProperties' => null
        ];
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint(
                    'https://example.test',
                    authSchemes: [
                        new EndpointAuthScheme(
                            'smithy.api#httpBearerAuth'
                        )
                    ]
                )
            ),
            supportedAuthSchemes: [
                $this->recordingAuthScheme(
                    'aws.auth#sigv4',
                    $seen,
                    ['identity' => 'modeled'],
                    ['signer' => 'modeled']
                ),
                $this->recordingAuthScheme(
                    'smithy.api#httpBearerAuth',
                    $seen,
                    ['identity' => 'endpoint'],
                    [
                        'signer' => 'endpoint',
                        'scope' => 'scheme'
                    ]
                )
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'aws.auth#sigv4',
                'smithy.api#httpBearerAuth'
            ]),
            authSchemePreference: ['sigv4']
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(
            'smithy.api#httpBearerAuth',
            $seen->selectedScheme
        );
        $this->assertSame(
            ['identity' => 'endpoint'],
            $seen->identityProperties
        );
        $this->assertSame(
            [
                'signer' => 'endpoint',
                'scope' => 'scheme'
            ],
            $seen->signerProperties
        );
    }

    public function testAttemptContextTracksEndpointSelectedIdentity(): void
    {
        $modeledIdentity = new class implements IdentityInterface {
            public function getExpirationTime(): ?float
            {
                return null;
            }
        };
        $endpointIdentity = new class implements IdentityInterface {
            public function getExpirationTime(): ?float
            {
                return null;
            }
        };
        $modeledResolver = $this->createMock(
            IdentityResolverInterface::class
        );
        $modeledResolver->method('resolveIdentity')->willReturn(
            IdentityResult::success($modeledIdentity)
        );
        $endpointResolver = $this->createMock(
            IdentityResolverInterface::class
        );
        $endpointResolver->method('resolveIdentity')->willReturn(
            IdentityResult::success($endpointIdentity)
        );
        $modeledScheme = new TestAuthSchemeFixture(
            'smithy.test#modeled',
            $modeledResolver
        );
        $endpointScheme = new TestAuthSchemeFixture(
            'smithy.test#endpoint',
            $endpointResolver
        );
        $observer = new class extends AbstractInterceptor {
            public ?OutputContext $attempt = null;

            public function readAfterAttempt(
                OutputContext $ctx,
                ?\Throwable $error
            ): void
            {
                $this->attempt = $ctx;
            }
        };
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                (new MockHttpClient())->willReturn('body')
            ),
            endpointResolver: new StaticEndpointResolver(new Endpoint(
                'https://example.test',
                authSchemes: [
                    new EndpointAuthScheme('smithy.test#endpoint')
                ]
            )),
            interceptors: new InterceptorChain($observer),
            supportedAuthSchemes: [$modeledScheme, $endpointScheme],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'smithy.test#modeled'
            ])
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame($endpointIdentity, $observer->attempt?->identity);
        $this->assertSame(
            $endpointResolver,
            $observer->attempt?->identityResolver
        );
    }

    public function testFailedEndpointIdentityDoesNotKeepModeledIdentity(): void
    {
        $modeledIdentity = new class implements IdentityInterface {
            public function getExpirationTime(): ?float
            {
                return null;
            }
        };
        $modeledResolver = $this->createMock(
            IdentityResolverInterface::class
        );
        $modeledResolver->method('resolveIdentity')->willReturn(
            IdentityResult::success($modeledIdentity)
        );
        $observer = new class extends AbstractInterceptor {
            public ?OutputContext $attempt = null;

            public function readAfterAttempt(
                OutputContext $ctx,
                ?\Throwable $error
            ): void
            {
                $this->attempt ??= $ctx;
            }
        };
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                (new MockHttpClient())->willReturn('body')
            ),
            endpointResolver: new StaticEndpointResolver(new Endpoint(
                'https://example.test',
                authSchemes: [
                    new EndpointAuthScheme('smithy.test#endpoint')
                ]
            )),
            interceptors: new InterceptorChain($observer),
            supportedAuthSchemes: [
                new TestAuthSchemeFixture(
                    'smithy.test#modeled',
                    $modeledResolver
                ),
                $this->missingAuthScheme(
                    'smithy.test#endpoint',
                    'endpoint identity failed'
                )
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'smithy.test#modeled'
            ])
        ));

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected identity resolution to fail.');
        } catch (IdentityNotFoundException) {
        }

        $this->assertNotNull($observer->attempt);
        $this->assertNull($observer->attempt->identity);
    }

    public function testIdentityMissDoesNotFallBackToAnotherScheme(): void
    {
        $seen = (object) ['selectedScheme' => null];
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [
                $this->missingAuthScheme(
                    'smithy.api#httpBearerAuth',
                    'token resolution failed'
                ),
                $this->recordingAuthScheme('aws.auth#sigv4', $seen)
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'smithy.api#httpBearerAuth',
                'aws.auth#sigv4'
            ]),
            authSchemePreference: ['httpBearerAuth']
        ));

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected identity resolution to fail.');
        } catch (IdentityNotFoundException $error) {
            $this->assertStringContainsString(
                'token resolution failed',
                $error->getMessage()
            );
        }

        $this->assertNull($seen->selectedScheme);
        $this->assertSame(0, $http->sendCallCount);
    }

    public function testNoUsableSchemeFailsWhenEveryResolverIsNull(): void
    {
        $http = (new MockHttpClient())->willReturn('body');
        $nullResolverScheme = new class implements AuthSchemeInterface {
            public function getSchemeId(): string
            {
                return 'smithy.api#httpBearerAuth';
            }

            public function getIdentityProperties(array $context): array
            {
                return [];
            }

            public function getIdentityResolver(
                \Smithy\Client\Auth\IdentityResolversInterface $resolvers
            ): ?IdentityResolverInterface {
                return null;
            }

            public function getSignerProperties(array $context): array
            {
                return [];
            }

            public function getSigner(): SignerInterface
            {
                return \Smithy\Client\Auth\NullSigner::instance();
            }
        };
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$nullResolverScheme],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'smithy.api#httpBearerAuth'
            ])
        ));

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected auth resolution to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString(
                'No supported auth scheme',
                $error->getMessage()
            );
        }

        $this->assertSame(0, $http->sendCallCount);
    }

    public function testNullResolverSchemeFallsBackToNextOption(): void
    {
        $seen = (object) ['selectedScheme' => null];
        $http = (new MockHttpClient())->willReturn('body');
        $nullResolverScheme = new class implements AuthSchemeInterface {
            public function getSchemeId(): string
            {
                return 'smithy.api#httpBearerAuth';
            }

            public function getIdentityProperties(array $context): array
            {
                return [];
            }

            public function getIdentityResolver(
                \Smithy\Client\Auth\IdentityResolversInterface $resolvers
            ): ?IdentityResolverInterface {
                return null;
            }

            public function getSignerProperties(array $context): array
            {
                return [];
            }

            public function getSigner(): SignerInterface
            {
                return \Smithy\Client\Auth\NullSigner::instance();
            }
        };
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [
                $nullResolverScheme,
                $this->recordingAuthScheme('aws.auth#sigv4', $seen)
            ],
            authSchemeResolver: new StaticAuthSchemeResolver([
                'smithy.api#httpBearerAuth',
                'aws.auth#sigv4'
            ])
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame('aws.auth#sigv4', $seen->selectedScheme);
        $this->assertSame(1, $http->sendCallCount);
    }

    public function testProtocolIsCalledExactlyOncePerAttempt(): void
    {
        $protocol = new FakeProtocol();
        [$pipeline, $http] = $this->buildPipeline(protocol: $protocol);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(1, $http->sendCallCount);
    }

    public function testRetryDelayIsHonoredBeforeNextAttempt(): void
    {
        $loop = $this->installFakeEventLoop();
        $http = (new MockHttpClient())
            ->queueException(new \RuntimeException('temporary'))
            ->queueResponse('server-body');
        $successfulState = null;
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static fn(
                string $scope,
                int $requestFlags = 0
            ): RetryState => new RetryState($scope, $requestFlags)
        );
        $strategy->method('refresh')->willReturnCallback(
            static function (
                RetryState $state,
                \Throwable $error
            ): float {
                ++$state->attempt;

                return 1.25;
            }
        );
        $strategy->method('recordSuccess')->willReturnCallback(
            static function (RetryState $state) use (
                &$successfulState
            ): void {
                $successfulState = $state;
            }
        );
        $strategy->method('maxAttempts')->willReturn(2);

        [$pipeline, $transport] = $this->buildPipeline(
            interceptors: [new RecordingInterceptor()],
            http: $http,
            retryStrategy: $strategy
        );

        $out = $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput('hello')
        );

        $this->assertSame([1.25], $loop->delays);
        $this->assertSame(2, $transport->sendCallCount);
        $this->assertNotSame(
            $transport->sentRequests[0],
            $transport->sentRequests[1]
        );
        $this->assertSame(
            (string) $transport->sentRequests[0]->getUri(),
            (string) $transport->sentRequests[1]->getUri()
        );
        $this->assertInstanceOf(Request::class, $transport->sentRequests[0]);
        $this->assertInstanceOf(Request::class, $transport->sentRequests[1]);
        $this->assertSame(2, $successfulState?->attempt);
        $this->assertSame('server-body', $out->value);
    }

    #[DataProvider('attemptScopedResolutionCases')]
    public function testResolvesIdentityAndEndpointForEveryAttempt(
        bool $withInterceptors
    ): void
    {
        $events = [];
        $signedUris = [];
        $identityResolver = $this->createMock(
            IdentityResolverInterface::class
        );
        $identityResolver
            ->expects($this->exactly(2))
            ->method('resolveIdentity')
            ->willReturnCallback(
                static function () use (&$events): IdentityResult {
                    $events[] = 'identity';

                    return IdentityResult::success(
                        AnonymousIdentity::instance()
                    );
                }
            );
        $signer = $this->createMock(SignerInterface::class);
        $signer
            ->expects($this->exactly(2))
            ->method('sign')
            ->willReturnCallback(
                static function (
                    TransportRequestInterface $request,
                    IdentityInterface $identity,
                    array $properties = []
                ) use (&$events, &$signedUris): SignerResult {
                    $events[] = 'sign';
                    $signedUris[] = (string) HttpTransportRequest::expect(
                        $request
                    )->request->getUri();

                    return new SignerResult($request);
                }
            );
        $scheme = new TestAuthSchemeFixture(
            'smithy.test#auth',
            $identityResolver,
            $signer
        );
        $authResolver = $this->createMock(
            AuthSchemeResolverInterface::class
        );
        $authResolver
            ->expects($this->once())
            ->method('resolveAuthScheme')
            ->willReturnCallback(
                static function () use (
                    &$events,
                    $scheme
                ): ResolvedAuthScheme {
                    $events[] = 'auth';

                    return new ResolvedAuthScheme($scheme);
                }
            );
        $authResolver
            ->expects($this->exactly(2))
            ->method('resolveEndpointAuthScheme')
            ->willReturnCallback(
                static function (
                    ResolvedAuthScheme $resolvedAuthScheme
                ) use (&$events): ResolvedAuthScheme {
                    $events[] = 'endpoint-auth';

                    return $resolvedAuthScheme;
                }
            );
        $endpointResolver = $this->createMock(
            EndpointResolverInterface::class
        );
        $endpointCall = 0;
        $endpointResolver
            ->expects($this->exactly(2))
            ->method('resolveEndpoint')
            ->willReturnCallback(
                static function () use (&$events, &$endpointCall): Endpoint
                {
                    $events[] = 'endpoint';
                    ++$endpointCall;

                    return new Endpoint(
                        "https://endpoint-{$endpointCall}.test/base"
                    );
                }
            );
        $protocol = $this->createMock(ProtocolInterface::class);
        $protocol->method('getMessageExchange')->willReturn(
            HttpMessageExchange::instance()
        );
        $protocol
            ->expects($this->once())
            ->method('serializeRequest')
            ->with(
                FakeOperation::instance(),
                $this->isInstanceOf(FakeInput::class)
            )
            ->willReturn(new HttpTransportRequest(
                new Request(
                    'POST',
                    new Uri('/operation'),
                    [],
                    'input'
                )
            ));
        $protocol
            ->expects($this->exactly(2))
            ->method('setServiceEndpoint')
            ->willReturnCallback(
                static function (
                    TransportRequestInterface $request,
                    Endpoint $endpoint
                ): TransportRequestInterface
                {
                    $transportRequest = HttpTransportRequest::expect($request);
                    $uri = new Uri(
                        \rtrim($endpoint->url, '/')
                            . $transportRequest
                                ->request
                                ->getUri()
                                ->getPath()
                    );

                    return $transportRequest->withRequest(
                        $transportRequest->request->withUri($uri)
                    );
                }
            );
        $protocol
            ->method('deserializeResponse')
            ->willReturn(new FakeOutput('server-body'));
        $http = (new MockHttpClient())
            ->queueException(new \RuntimeException('temporary'))
            ->queueResponse('server-body');
        $interceptors = $withInterceptors
            ? new InterceptorChain(new RecordingInterceptor())
            : new InterceptorChain();
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: $protocol,
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: $endpointResolver,
            interceptors: $interceptors,
            retryStrategy: $this->retryOnceStrategy(),
            supportedAuthSchemes: [$scheme],
            authSchemeResolver: $authResolver
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame([
            'auth',
            'identity',
            'endpoint',
            'endpoint-auth',
            'sign',
            'identity',
            'endpoint',
            'endpoint-auth',
            'sign'
        ], $events);
        $this->assertSame([
            'https://endpoint-1.test/base/operation',
            'https://endpoint-2.test/base/operation'
        ], $signedUris);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function attemptScopedResolutionCases(): array
    {
        return [
            'without interceptors' => [false],
            'with interceptors' => [true]
        ];
    }

    public function testRetriesStartFromRequestBeforeAttemptMutations(): void
    {
        $interceptor = $this
            ->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['modifyBeforeSigning'])
            ->getMock();
        $interceptor->method('modifyBeforeSigning')->willReturnCallback(
            static function (
                RequestContext $ctx
            ): TransportRequestInterface {
                $request = HttpTransportRequest::expect($ctx->request);

                return $request->withRequest(
                    $request->request->withAddedHeader(
                        'x-attempt',
                        'value'
                    )
                );
            }
        );
        $http = (new MockHttpClient())
            ->queueException(new \RuntimeException('temporary'))
            ->queueResponse('server-body');
        [$pipeline] = $this->buildPipeline(
            interceptors: [$interceptor],
            http: $http,
            retryStrategy: $this->retryOnceStrategy()
        );

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(
            ['value'],
            $http->sentRequests[0]->getHeader('x-attempt')
        );
        $this->assertSame(
            ['value'],
            $http->sentRequests[1]->getHeader('x-attempt')
        );
    }

    public function testChecksumBufferedBodySurvivesRetry(): void
    {
        $bodies = [];
        $checksums = [];
        $capture = static function (
            RequestInterface $request
        ) use (&$bodies, &$checksums): void {
            $bodies[] = $request->getBody()->getContents();
            $checksums[] = $request->getHeaderLine('Content-MD5');
        };
        $http = (new MockHttpClient())
            ->queueHandler(
                static function (
                    RequestInterface $request
                ) use ($capture): Response {
                    $capture($request);

                    throw new \RuntimeException('temporary');
                }
            )
            ->queueHandler(
                static function (
                    RequestInterface $request
                ) use ($capture): Response {
                    $capture($request);

                    return new Response(200, [], 'server-body');
                }
            );
        [$pipeline] = $this->buildPipeline(
            interceptors: [new HttpChecksumInterceptor()],
            http: $http,
            protocol: new FakeProtocol(
                requestBody: new TestStreamFixture(
                    'payload',
                    sizeKnown: true,
                    seekable: false
                )
            ),
            retryStrategy: $this->retryOnceStrategy()
        );

        $pipeline->execute(
            $this->operationWithTraits([
                Schema::T_HTTP_CHECKSUM_REQUIRED => true
            ]),
            new FakeInput()
        );

        $this->assertSame(['payload', 'payload'], $bodies);
        $this->assertSame([
            'Mhw89IbtUJFk7eweGYH+yA==',
            'Mhw89IbtUJFk7eweGYH+yA=='
        ], $checksums);
    }

    public function testChecksumHonorsContentMd5RequestOverride(): void
    {
        $serializedBody = null;
        $observer = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['readAfterSerialization'])
            ->getMock();
        $observer->method('readAfterSerialization')->willReturnCallback(
            static function (RequestContext $ctx) use (
                &$serializedBody
            ): void {
                $serializedBody = HttpTransportRequest::expect(
                    $ctx->request
                )->request->getBody();
            }
        );
        $body = new TestStreamFixture(
            'payload',
            sizeKnown: true,
            seekable: false
        );
        [$pipeline, $http] = $this->buildPipeline(
            interceptors: [$observer, new HttpChecksumInterceptor()],
            protocol: new FakeProtocol(requestBody: $body)
        );

        $pipeline->execute(
            $this->operationWithTraits([
                Schema::T_HTTP_CHECKSUM_REQUIRED => true
            ]),
            new FakeInput(),
            RequestOverrideConfig::fromArray([
                '@http' => [
                    'headers' => ['Content-MD5' => 'preset']
                ]
            ])
        );

        $this->assertSame(
            $serializedBody,
            $http->sentRequests[0]->getBody()
        );
        $this->assertSame(
            'preset',
            $http->sentRequests[0]->getHeaderLine('Content-MD5')
        );
    }

    #[DataProvider('retryPipelineModes')]
    public function testRetriesRewindBodiesAndTrailers(
        bool $withInterceptors
    ): void
    {
        $bodies = [];
        $trailerResets = 0;
        $trailers = new RequestTrailers(
            static fn(): array => ['x-checksum' => 'value'],
            retryReset: static function () use (&$trailerResets): void {
                ++$trailerResets;
            }
        );
        $http = (new MockHttpClient())
            ->withDefaultRequestOptions(
                new HttpRequestOptions(trailers: $trailers)
            )
            ->queueHandler(
                static function (
                    RequestInterface $request,
                    HttpRequestOptions $options
                ) use (&$bodies): Response {
                    $bodies[] = $request->getBody()->getContents();
                    $options->trailers?->resolve();

                    throw new \RuntimeException('temporary');
                }
            )
            ->queueHandler(
                static function (
                    RequestInterface $request,
                    HttpRequestOptions $options
                ) use (&$bodies): Response {
                    $bodies[] = $request->getBody()->getContents();
                    $options->trailers?->resolve();

                    return new Response(200, [], 'server-body');
                }
            );
        [$pipeline] = $this->buildPipeline(
            interceptors: $withInterceptors
                ? [new RecordingInterceptor()]
                : [],
            http: $http,
            retryStrategy: $this->retryOnceStrategy()
        );

        $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput('hello')
        );

        $this->assertSame(['input:hello', 'input:hello'], $bodies);
        $this->assertSame(1, $trailerResets);
    }

    #[DataProvider('retryPipelineModes')]
    public function testConsumedNonReplayableBodyIsNotRetried(
        bool $withInterceptors
    ): void
    {
        $http = (new MockHttpClient())
            ->queueHandler(
                static function (RequestInterface $request): Response {
                    $request->getBody()->getContents();

                    throw new \RuntimeException('upload failed');
                }
            )
            ->queueResponse('server-body');
        [$pipeline] = $this->buildPipeline(
            interceptors: $withInterceptors
                ? [new RecordingInterceptor()]
                : [],
            http: $http,
            protocol: new FakeProtocol(
                requestBody: new TestStreamFixture(
                    'payload',
                    sizeKnown: true,
                    seekable: false
                )
            ),
            retryStrategy: $this->retryOnceStrategy()
        );

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected the upload failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('upload failed', $error->getMessage());
        }

        $this->assertSame(1, $http->sendCallCount);
    }

    #[DataProvider('throwingRequestStreamStateMethods')]
    public function testRetryStateInspectionPreservesAttemptError(
        string $throwingMethod
    ): void
    {
        $attemptError = new \RuntimeException('attempt failed');
        $inspectionError = new \RuntimeException('inspection failed');
        $body = $this->createMock(RequestStreamInterface::class);
        $body->method('getSize')->willReturn(7);
        $body->method('isReplayable')->willReturn(false);
        $body->method('isSeekable')->willReturn(false);
        if ($throwingMethod === 'started') {
            $body->method('started')->willThrowException($inspectionError);
            $body->expects(self::never())->method('fullyConsumed');
        } else {
            $body->method('started')->willReturn(false);
            $body->method('fullyConsumed')->willThrowException(
                $inspectionError
            );
        }
        $http = (new MockHttpClient())->queueException($attemptError);
        [$pipeline] = $this->buildPipeline(
            http: $http,
            protocol: new FakeProtocol(requestBody: $body),
            retryStrategy: $this->retryOnceStrategy()
        );

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected the attempt to fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame($attemptError, $error);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function throwingRequestStreamStateMethods(): iterable
    {
        yield 'started' => ['started'];
        yield 'fully consumed' => ['fullyConsumed'];
    }

    #[DataProvider('retryPipelineModes')]
    public function testNonReplayableBodyBailoutDoesNotSpendRetryTokens(
        bool $withInterceptors
    ): void
    {
        // Regression: prepareRequestForRetry() must be checked before the
        // strategy spends tokens. A non-replayable body aborts the retry, and
        // the bucket capacity must be left untouched (no permanent leak).
        $strategy = new StandardRetryStrategy();
        $scope = '';
        $capacityBefore = $strategy->begin($scope)->capacityRemaining;

        $http = (new MockHttpClient())
            ->queueException($this->networkException())
            ->queueResponse('server-body');
        [$pipeline] = $this->buildPipeline(
            interceptors: $withInterceptors
                ? [new RecordingInterceptor()]
                : [],
            http: $http,
            protocol: new FakeProtocol(
                requestBody: new TestStreamFixture(
                    'payload',
                    sizeKnown: true,
                    seekable: false
                )
            ),
            retryStrategy: $strategy
        );

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected the network failure to surface.');
        } catch (NetworkException) {
            // Expected: a non-replayable body cannot be retried.
        }

        $this->assertSame(1, $http->sendCallCount);
        $this->assertSame(
            $capacityBefore,
            $strategy->begin($scope)->capacityRemaining,
            'Retry tokens must not be spent when the request cannot be '
            . 'replayed.'
        );
    }

    #[DataProvider('retryPipelineModes')]
    public function testUntouchedNonReplayableBodyCanRetry(
        bool $withInterceptors
    ): void
    {
        $bodies = [];
        $http = (new MockHttpClient())
            ->queueException(new \RuntimeException('connect failed'))
            ->queueHandler(
                static function (
                    RequestInterface $request
                ) use (&$bodies): Response {
                    $bodies[] = $request->getBody()->getContents();

                    return new Response(200, [], 'server-body');
                }
            );
        [$pipeline] = $this->buildPipeline(
            interceptors: $withInterceptors
                ? [new RecordingInterceptor()]
                : [],
            http: $http,
            protocol: new FakeProtocol(
                requestBody: new TestStreamFixture(
                    'payload',
                    sizeKnown: true,
                    seekable: false
                )
            ),
            retryStrategy: $this->retryOnceStrategy()
        );

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(['payload'], $bodies);
        $this->assertSame(2, $http->sendCallCount);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function retryPipelineModes(): iterable
    {
        yield 'without interceptors' => [false];
        yield 'with interceptors' => [true];
    }

    /**
     * @return iterable<string, array{bool, int|null}>
     */
    public static function retryStrategyAttemptLimitCases(): iterable
    {
        yield 'without interceptors' => [false, null];
        yield 'with interceptors' => [true, null];
        yield 'override cannot raise limit without interceptors' => [false, 4];
        yield 'override cannot raise limit with interceptors' => [true, 4];
    }

    public function testDefaultStrategyRetriesTransientFailureOnce(): void
    {
        $loop = $this->installFakeEventLoop();
        $http = (new MockHttpClient())
            ->queueException($this->networkException())
            ->queueResponse('server-body');
        [$pipeline, $transport] = $this->buildPipeline(http: $http);

        $pipeline->execute(FakeOperation::instance(), new FakeInput('hello'));

        $this->assertSame(2, $transport->sendCallCount);
        $this->assertCount(1, $loop->delays);
        $this->assertGreaterThan(0.0, $loop->delays[0]);
        $this->assertLessThanOrEqual(0.05, $loop->delays[0]);
    }

    public function testDefaultStrategyStopsAfterThreeAttempts(): void
    {
        $this->installFakeEventLoop();
        $http = (new MockHttpClient())
            ->fallbackException($this->networkException());
        [$pipeline, $transport] = $this->buildPipeline(http: $http);

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected the final attempt to fail.');
        } catch (NetworkException) {
            $this->assertSame(3, $transport->sendCallCount);
        }
    }

    public function testDefaultStrategyDoesNotRetryNonRetryableFailure(): void
    {
        $this->installFakeEventLoop();
        $http = (new MockHttpClient())->fallbackException(
            new \RuntimeException('permanent')
        );
        [$pipeline, $transport] = $this->buildPipeline(http: $http);

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected failure.');
        } catch (\RuntimeException) {
            $this->assertSame(1, $transport->sendCallCount);
        }
    }

    public function testNoRetryStrategyOptOutMakesSingleAttempt(): void
    {
        $this->installFakeEventLoop();
        $http = (new MockHttpClient())
            ->fallbackException($this->networkException());
        [$pipeline, $transport] = $this->buildPipeline(
            http: $http,
            retryStrategy: NoRetryStrategy::instance()
        );

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected failure.');
        } catch (NetworkException) {
            $this->assertSame(1, $transport->sendCallCount);
        }
    }

    public function testStandardStrategyWithPinnedJitterDelaysExactly(): void
    {
        $loop = $this->installFakeEventLoop();
        $http = (new MockHttpClient())
            ->fallbackException($this->networkException());
        [$pipeline] = $this->buildPipeline(
            http: $http,
            retryStrategy: new StandardRetryStrategy(
                random: static fn(): float => 0.0
            )
        );

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
        } catch (NetworkException) {
        }

        $this->assertEqualsWithDelta([0.05, 0.1], $loop->delays, 1e-9);
    }

    private function networkException(): NetworkException
    {
        return new NetworkException(
            'connection reset',
            new \Smithy\Http\Message\Request('GET', 'https://example.test/')
        );
    }

    #[DataProvider('retryStrategyAttemptLimitCases')]
    public function testRetryStrategyCapsAttempts(
        bool $withInterceptors,
        ?int $overrideRetries
    ): void
    {
        $http = (new MockHttpClient())->fallbackException(
            new \RuntimeException('temporary')
        );
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static fn(
                string $scope,
                int $requestFlags = 0
            ): RetryState => new RetryState($scope, $requestFlags)
        );
        $strategy->expects(self::exactly(2))
            ->method('refresh')
            ->willReturnCallback(
                static function (
                    RetryState $state,
                    \Throwable $error
                ): float {
                    ++$state->attempt;

                    return 0.0;
                }
            );
        $strategy->expects(self::never())->method('recordSuccess');
        $strategy->expects(self::once())->method('maxAttempts')->willReturn(2);
        [$pipeline, $transport] = $this->buildPipeline(
            interceptors: $withInterceptors
                ? [new RecordingInterceptor()]
                : [],
            http: $http,
            retryStrategy: $strategy
        );
        $overrides = $overrideRetries === null
            ? null
            : RequestOverrideConfig::fromArray([
                '@retries' => $overrideRetries
            ]);

        try {
            $pipeline->execute(
                FakeOperation::instance(),
                new FakeInput(),
                $overrides
            );
            $this->fail('Expected the final attempt to fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('temporary', $error->getMessage());
        }

        $this->assertSame(2, $transport->sendCallCount);
    }

    #[DataProvider('retryPipelineModes')]
    public function testRequestRetryOverrideCapsAttempts(
        bool $withInterceptors
    ): void
    {
        $http = (new MockHttpClient())
            ->queueException(new \RuntimeException('temporary'))
            ->queueResponse('server-body');
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static fn(
                string $scope,
                int $requestFlags = 0
            ): RetryState => new RetryState($scope, $requestFlags)
        );
        $strategy->expects(self::never())->method('refresh');
        $strategy->expects(self::never())->method('recordSuccess');
        $strategy->method('maxAttempts')->willReturn(3);
        [$pipeline, $transport] = $this->buildPipeline(
            interceptors: $withInterceptors
                ? [new RecordingInterceptor()]
                : [],
            http: $http,
            retryStrategy: $strategy
        );

        try {
            $pipeline->execute(
                FakeOperation::instance(),
                new FakeInput(),
                RequestOverrideConfig::fromArray(['@retries' => 0])
            );
            $this->fail('Expected the first attempt to fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('temporary', $error->getMessage());
        }

        $this->assertSame(1, $transport->sendCallCount);
    }

    public function testRecordSuccessFailureDoesNotRetryWithoutInterceptors(): void
    {
        $http = (new MockHttpClient())->willReturn('server-body');
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static fn(
                string $scope,
                int $requestFlags = 0
            ): RetryState => new RetryState($scope, $requestFlags)
        );
        $strategy->expects(self::never())->method('refresh');
        $strategy->expects(self::once())
            ->method('recordSuccess')
            ->willThrowException(new \RuntimeException('record failed'));
        $strategy->method('maxAttempts')->willReturn(2);
        [$pipeline, $transport] = $this->buildPipeline(
            http: $http,
            retryStrategy: $strategy
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('record failed');

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
        } finally {
            $this->assertSame(1, $transport->sendCallCount);
        }
    }

    public function testRetryRequestFlagsArePassedToStrategyWithoutInterceptors(): void
    {
        $capturedRequestFlags = -1;
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static function (
                string $scope,
                int $requestFlags = 0
            ) use (&$capturedRequestFlags): RetryState {
                $capturedRequestFlags = $requestFlags;

                return new RetryState($scope, $requestFlags);
            }
        );
        $strategy->expects(self::never())->method('refresh');
        $strategy->method('maxAttempts')->willReturn(1);
        $resolver = $this->createStub(
            RetryRequestFlagResolverInterface::class
        );
        $resolver->method('resolve')->willReturn(7);
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                (new MockHttpClient())->willReturn('server-body')
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            retryStrategy: $strategy,
            context: [
                RetryRequestFlagResolverInterface::CONFIG_KEY => $resolver
            ]
        );
        $pipeline = new ClientPipeline($config);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(7, $capturedRequestFlags);
    }

    public function testRetryRequestFlagsArePassedToStrategyWithInterceptors(): void
    {
        $capturedRequestFlags = -1;
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static function (
                string $scope,
                int $requestFlags = 0
            ) use (&$capturedRequestFlags): RetryState {
                $capturedRequestFlags = $requestFlags;

                return new RetryState($scope, $requestFlags);
            }
        );
        $strategy->expects(self::never())->method('refresh');
        $strategy->method('maxAttempts')->willReturn(1);
        $resolver = $this->createStub(
            RetryRequestFlagResolverInterface::class
        );
        $resolver->method('resolve')->willReturn(11);
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                (new MockHttpClient())->willReturn('server-body')
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            interceptors: new InterceptorChain(
                new RecordingInterceptor()
            ),
            retryStrategy: $strategy,
            context: [
                RetryRequestFlagResolverInterface::CONFIG_KEY => $resolver
            ]
        );
        $pipeline = new ClientPipeline($config);

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(11, $capturedRequestFlags);
    }

    public function testRetryInitializationFailureSkipsAttempt(): void
    {
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willThrowException(
            new \RuntimeException('retry initialization failed')
        );
        $strategy->expects(self::never())->method('refresh');
        $strategy->expects(self::never())->method('recordSuccess');
        $strategy->method('maxAttempts')->willReturn(1);
        $recorder = new RecordingInterceptor();
        [$pipeline, $http] = $this->buildPipeline(
            interceptors: [$recorder],
            retryStrategy: $strategy
        );

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected retry initialization to fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame(
                'retry initialization failed',
                $error->getMessage()
            );
        }

        $this->assertSame(0, $http->sendCallCount);
        $this->assertNotContains('readBeforeAttempt', $recorder->calls);
        $this->assertContains('modifyBeforeCompletion', $recorder->calls);
        $this->assertContains('readAfterExecution', $recorder->calls);
    }

    public function testTerminalRetryDelayIsHonoredBeforeSurfacingFailure(): void
    {
        $loop = $this->installFakeEventLoop();
        $http = (new MockHttpClient())->queueException(
            new \RuntimeException('permanent')
        );
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static fn(
                string $scope,
                int $requestFlags = 0
            ): RetryState => new RetryState($scope, $requestFlags)
        );
        $strategy->method('refresh')->willReturnCallback(
            static function (
                RetryState $state,
                \Throwable $error
            ): never {
                throw new TokenAcquisitionFailedException(
                    'no more retries',
                    previous: $error,
                    state: $state,
                    delay: 0.5
                );
            }
        );
        $strategy->expects(self::never())->method('recordSuccess');
        $strategy->method('maxAttempts')->willReturn(1);

        [$pipeline, $transport] = $this->buildPipeline(
            http: $http,
            retryStrategy: $strategy
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('permanent');

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
        } finally {
            $this->assertSame([0.5], $loop->delays);
            $this->assertSame(1, $transport->sendCallCount);
        }
    }

    public function testInterceptorCanReplaceOutputInCompletion(): void
    {
        $replacer = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['modifyBeforeCompletion'])
            ->getMock();
        $replacer->method('modifyBeforeCompletion')->willReturn(
            new FakeOutput('replaced-by-interceptor')
        );

        [$pipeline] = $this->buildPipeline([$replacer]);

        $out = $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertInstanceOf(FakeOutput::class, $out);
        $this->assertSame('replaced-by-interceptor', $out->value);
    }

    public function testModifyErrorSkipsAttemptAndReachesCompletion(): void
    {
        $recorder = new RecordingInterceptor();
        $thrower = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['modifyBeforeSerialization'])
            ->getMock();
        $thrower->method('modifyBeforeSerialization')->willThrowException(
            new \RuntimeException('boom-serialize')
        );
        [$pipeline] = $this->buildPipeline([$recorder, $thrower]);

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $error) {
            $this->assertSame('boom-serialize', $error->getMessage());
        }

        $this->assertContains('readBeforeExecution', $recorder->calls);
        $this->assertContains('modifyBeforeCompletion', $recorder->calls);
        $this->assertContains('readAfterExecution', $recorder->calls);
        $this->assertNotContains('readBeforeAttempt', $recorder->calls);
        $this->assertNotContains('readBeforeSigning', $recorder->calls);
    }

    public function testCompletionCanSuppressException(): void
    {
        $rescuer = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['modifyBeforeCompletion'])
            ->getMock();
        $rescuer->method('modifyBeforeCompletion')->willReturn(
            new FakeOutput('rescued')
        );
        $http = (new MockHttpClient())->willThrow(
            new \RuntimeException('never surfaced')
        );
        [$pipeline] = $this->buildPipeline([$rescuer], http: $http);

        $output = $pipeline->execute(
            FakeOperation::instance(),
            new FakeInput()
        );

        $this->assertSame('rescued', $output->value);
    }

    public function testTransmitErrorRunsAttemptCompletion(): void
    {
        $http = (new MockHttpClient())->willThrow(
            new \RuntimeException('connect-refused')
        );
        $recorder = new RecordingInterceptor();
        [$pipeline] = $this->buildPipeline([$recorder], http: $http);

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $error) {
            $this->assertSame('connect-refused', $error->getMessage());
        }

        $this->assertContains('readBeforeAttempt', $recorder->calls);
        $this->assertContains(
            'modifyBeforeAttemptCompletion',
            $recorder->calls
        );
        $this->assertContains('readAfterAttempt', $recorder->calls);
        $this->assertContains('modifyBeforeCompletion', $recorder->calls);
        $this->assertContains('readAfterExecution', $recorder->calls);
        $this->assertNotContains(
            'readBeforeDeserialization',
            $recorder->calls
        );
        $this->assertNotContains(
            'readAfterDeserialization',
            $recorder->calls
        );
    }

    public function testDeserializationErrorRunsAttemptCompletion(): void
    {
        $protocol = new FakeProtocol(
            deserializeError: new \RuntimeException('bad-body')
        );
        $recorder = new RecordingInterceptor();
        [$pipeline] = $this->buildPipeline(
            [$recorder],
            protocol: $protocol
        );

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $error) {
            $this->assertSame('bad-body', $error->getMessage());
        }

        $this->assertContains(
            'readBeforeDeserialization',
            $recorder->calls
        );
        $this->assertContains(
            'readAfterDeserialization',
            $recorder->calls
        );
        $this->assertContains(
            'modifyBeforeAttemptCompletion',
            $recorder->calls
        );
        $this->assertContains('readAfterExecution', $recorder->calls);
    }

    public function testReadAfterExecutionRunsAfterEarlierError(): void
    {
        $sawExecutionEnd = false;
        $observer = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['readAfterExecution'])
            ->getMock();
        $observer->method('readAfterExecution')->willReturnCallback(
            static function (
                OutputContext $ctx,
                ?\Throwable $error
            ) use (&$sawExecutionEnd): void {
                $sawExecutionEnd = true;
            }
        );
        $thrower = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['modifyBeforeSerialization'])
            ->getMock();
        $thrower->method('modifyBeforeSerialization')->willThrowException(
            new \RuntimeException('boom')
        );
        [$pipeline] = $this->buildPipeline([$thrower, $observer]);

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
        } catch (\Throwable) {
        }

        $this->assertTrue($sawExecutionEnd);
    }

    public function testBatchedReadErrorsDoNotStopInterceptors(): void
    {
        $sawSecondHook = false;
        $thrower = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['readBeforeExecution'])
            ->getMock();
        $thrower->method('readBeforeExecution')->willThrowException(
            new \RuntimeException('batched-boom')
        );
        $observer = $this->getMockBuilder(AbstractInterceptor::class)
            ->onlyMethods(['readBeforeExecution'])
            ->getMock();
        $observer->method('readBeforeExecution')->willReturnCallback(
            static function (InputContext $ctx) use (
                &$sawSecondHook
            ): void {
                $sawSecondHook = true;
            }
        );
        [$pipeline] = $this->buildPipeline([$thrower, $observer]);

        try {
            $pipeline->execute(FakeOperation::instance(), new FakeInput());
        } catch (\Throwable) {
        }

        $this->assertTrue($sawSecondHook);
    }

    /**
     * @param list<AbstractInterceptor> $interceptors
     *
     * @return array{ClientPipeline, MockHttpClient, FakeProtocol}
     */
    private function buildPipeline(
        array $interceptors = [],
        ?MockHttpClient $http = null,
        ?FakeProtocol $protocol = null,
        ?RetryStrategyInterface $retryStrategy = null
    ): array
    {
        $http ??= (new MockHttpClient())->willReturn('server-body');
        $protocol ??= new FakeProtocol();
        $endpoint = new Endpoint('https://example.test');
        $config = new ClientConfig(
            protocol: $protocol,
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver($endpoint),
            interceptors: new InterceptorChain(...$interceptors),
            retryStrategy: $retryStrategy
        );

        return [new ClientPipeline($config), $http, $protocol];
    }

    private function installFakeEventLoop(): RecordingEventLoopFixture
    {
        $loop = new RecordingEventLoopFixture();

        Async::configure($loop);

        return $loop;
    }

    private function retryOnceStrategy(): RetryStrategyInterface
    {
        $strategy = $this->createMock(RetryStrategyInterface::class);
        $strategy->method('begin')->willReturnCallback(
            static fn(
                string $scope,
                int $requestFlags = 0
            ): RetryState => new RetryState($scope, $requestFlags)
        );
        $strategy->method('refresh')->willReturnCallback(
            static function (
                RetryState $state,
                \Throwable $error
            ): float {
                ++$state->attempt;

                return 0.0;
            }
        );
        $strategy->method('maxAttempts')->willReturn(2);

        return $strategy;
    }

    /**
     * @param array<int, mixed> $traits
     */
    private function operationWithTraits(array $traits): OperationInterface
    {
        $operation = $this->createStub(OperationInterface::class);
        $operation->method('getName')->willReturn('Fake');
        $operation->method('getEffectiveAuthSchemes')->willReturn([
            'smithy.api#noAuth'
        ]);
        $operation->method('getSchema')->willReturn(new Schema(
            id: 'smithy.tests#Fake',
            type: ShapeType::Operation,
            traits: $traits
        ));

        return $operation;
    }

    /**
     * @param list<AbstractInterceptor> $interceptors
     */
    private function assertEndpointAuthOverridesModeledAuth(
        array $interceptors
    ): void
    {
        $seen = (object) [
            'identityProperties' => null,
            'signerProperties' => null
        ];
        $scheme = $this->recordingAuthScheme(
            'smithy.test#endpointAuth',
            $seen,
            [
                'identity' => 'scheme',
                'shared' => 'identity'
            ],
            [
                'signer' => 'scheme',
                'scope' => 'scheme'
            ]
        );
        $http = (new MockHttpClient())->willReturn('body');
        $pipeline = new ClientPipeline(new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                $http
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint(
                    'https://example.test',
                    authSchemes: [
                        new EndpointAuthScheme('smithy.test#unsupported'),
                        new EndpointAuthScheme(
                            'smithy.test#endpointAuth',
                            ['scope' => 'endpoint']
                        )
                    ]
                )
            ),
            interceptors: new InterceptorChain(...$interceptors),
            supportedAuthSchemes: [$scheme]
        ));

        $pipeline->execute(FakeOperation::instance(), new FakeInput());

        $this->assertSame(
            [
                'identity' => 'scheme',
                'shared' => 'identity'
            ],
            $seen->identityProperties
        );
        $this->assertSame(
            [
                'signer' => 'scheme',
                'scope' => 'endpoint'
            ],
            $seen->signerProperties
        );
        $this->assertSame(1, $http->sendCallCount);
    }

    /**
     * @param array<string, mixed> $identityProperties
     * @param array<string, mixed> $signerProperties
     */
    private function recordingAuthScheme(
        string $schemeId,
        object $seen,
        array $identityProperties = [],
        array $signerProperties = []
    ): AuthSchemeInterface
    {
        $identityResolver = $this->createMock(
            IdentityResolverInterface::class
        );
        $identityResolver->method('resolveIdentity')->willReturnCallback(
            static function (array $properties = []) use (
                $seen
            ): IdentityResult {
                $seen->identityProperties = $properties;

                return IdentityResult::success(
                    AnonymousIdentity::instance()
                );
            }
        );
        $signer = $this->createMock(SignerInterface::class);
        $signer->method('sign')->willReturnCallback(
            static function (
                TransportRequestInterface $request,
                IdentityInterface $identity,
                array $properties = []
            ) use ($seen, $schemeId): SignerResult {
                $seen->signerProperties = $properties;
                if (\property_exists($seen, 'selectedScheme')) {
                    $seen->selectedScheme = $schemeId;
                }

                return new SignerResult($request);
            }
        );

        return new TestAuthSchemeFixture(
            $schemeId,
            $identityResolver,
            $signer,
            $identityProperties,
            $signerProperties
        );
    }

    private function missingAuthScheme(
        string $schemeId,
        string $message
    ): AuthSchemeInterface
    {
        return new TestAuthSchemeFixture(
            $schemeId,
            new NotFoundResolverFixture($message)
        );
    }
}
