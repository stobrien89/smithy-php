<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Tests\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smithy\Client\Auth\{
    AuthSchemeResolver,
    AuthSchemeInterface,
    BearerToken,
    IdentityResolvers,
    NoAuthScheme,
    StaticAuthSchemeResolver,
    StaticTokenProvider
};
use Smithy\Client\ClientConfig;
use Smithy\Client\ExplicitClientConfigField;
use Smithy\Client\Endpoint\StaticEndpointResolver;
use Smithy\Client\Interceptor\IdempotencyTokenInterceptor;
use Smithy\Client\Protocol\ProtocolResolver;
use Smithy\Client\Retry\{
    NoRetryStrategy,
    RetryStrategyInterface,
    StandardRetryStrategy
};
use Smithy\Core\Endpoint\Endpoint;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Shape\{
    ServiceInterface,
    ShapeType
};
use Smithy\Http\Client\HttpClientTransport;
use Smithy\Tests\Client\Dynamic\Utils\IdentifiedProtocolFixture;
use Smithy\Tests\Client\Fake\{
    FakeOperation,
    FakeProtocol,
    FakeService,
    RecordingInterceptor,
    RegionalClientConfigFixture,
    TestAuthSchemeFixture
};
use Smithy\Tests\Utils\Http\MockHttpClient;

/**
 * Copy-on-write semantics for {@see ClientConfig::withXxx()}.
 *
 * Each method must produce a new config with only the named field
 * replaced, leaving the original untouched. Subclass fields (verified
 * separately) survive every base-class `withXxx()` because `copyWith()`
 * dispatches through `new static(...)`.
 */
#[CoversClass(ClientConfig::class)]
final class ClientConfigTest extends TestCase
{
    private function baseConfig(): ClientConfig
    {
        return new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            )
        );
    }

    public function testWithProtocolReplacesOnlyProtocol(): void
    {
        $orig = $this->baseConfig();
        $newProto = new FakeProtocol();
        $new = $orig->withProtocol($newProto);

        $this->assertNotSame($orig, $new);
        $this->assertSame($newProto, $new->getProtocol());
        $this->assertSame($orig->transport, $new->transport);
        $this->assertSame($orig->endpointResolver, $new->endpointResolver);
    }

    public function testUnresolvedProtocolCannotBeRead(): void
    {
        $config = new ClientConfig(
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            protocolResolver: new ProtocolResolver([
                FakeProtocol::ID => $protocol = new FakeProtocol()
            ])
        );

        $this->expectException(\LogicException::class);
        $config->getProtocol();
    }

    public function testProtocolResolvesAfterServiceAttachment(): void
    {
        $config = new ClientConfig(
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            protocolResolver: new ProtocolResolver([
                FakeProtocol::ID => $protocol = new FakeProtocol()
            ])
        );
        $resolved = $config
            ->withService(FakeService::instance())
            ->resolveProtocol();

        self::assertSame($protocol, $resolved->getProtocol());
        self::assertNull($resolved->protocolId);
    }

    public function testReplacingResolverPreservesExplicitProtocol(): void
    {
        $protocol = new FakeProtocol();
        $config = new ClientConfig(
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            protocol: $protocol,
            service: FakeService::instance()
        );

        $updated = $config->withProtocolResolver(new ProtocolResolver());

        self::assertSame($protocol, $updated->getProtocol());
    }

    public function testReplacingResolverInvalidatesResolvedProtocol(): void
    {
        $first = new FakeProtocol();
        $second = new FakeProtocol();
        $config = new ClientConfig(
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            protocolResolver: new ProtocolResolver([
                FakeProtocol::ID => $first
            ]),
            service: FakeService::instance()
        );
        $resolved = $config->resolveProtocol();

        $updated = $resolved->withProtocolResolver(new ProtocolResolver([
            FakeProtocol::ID => $second
        ]));

        self::assertSame($second, $updated->getProtocol());
    }

    public function testChangingServiceInvalidatesResolvedProtocol(): void
    {
        $first = new IdentifiedProtocolFixture('example#first');
        $second = new IdentifiedProtocolFixture('example#second');
        $config = new ClientConfig(
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            protocolResolver: new ProtocolResolver([
                $first->getId() => $first,
                $second->getId() => $second
            ]),
            service: $this->serviceWithProtocol($first->getId())
        );
        $resolved = $config->resolveProtocol();

        $updated = $resolved
            ->withService($this->serviceWithProtocol($second->getId()))
            ->resolveProtocol();

        self::assertSame($second, $updated->getProtocol());
    }

    public function testListsRegisteredAndApplicableProtocols(): void
    {
        $protocol = new FakeProtocol();
        $config = $this->baseConfig()
            ->withProtocolResolver(new ProtocolResolver([
                FakeProtocol::ID => $protocol
            ]))
            ->withService(FakeService::instance());

        self::assertSame(
            [FakeProtocol::ID => $protocol],
            $config->getRegisteredProtocols()
        );
        self::assertSame(
            [FakeProtocol::ID => $protocol],
            $config->getApplicableProtocols()
        );
    }

    public function testWithTransportReplacesOnlyTransport(): void
    {
        $orig = $this->baseConfig();
        $transport = new HttpClientTransport(new MockHttpClient());
        $new = $orig->withTransport($transport);

        $this->assertSame($transport, $new->transport);
        $this->assertSame($orig->getProtocol(), $new->getProtocol());
    }

    public function testWithEndpointResolverReplacesOnlyEndpointResolver(): void
    {
        $orig = $this->baseConfig();
        $newResolver = new StaticEndpointResolver(
            new Endpoint('https://b.test')
        );
        $new = $orig->withEndpointResolver($newResolver);

        $this->assertSame($newResolver, $new->endpointResolver);
        $this->assertSame($orig->transport, $new->transport);
    }

    public function testWithRetryStrategyReplacesOnlyRetryStrategy(): void
    {
        $orig = $this->baseConfig();
        $newRetry = $this->createStub(RetryStrategyInterface::class);
        $new = $orig->withRetryStrategy($newRetry);

        $this->assertSame($newRetry, $new->retryStrategy);
        $this->assertInstanceOf(
            StandardRetryStrategy::class,
            $orig->retryStrategy
        );
    }

    public function testRetryStrategyDefaultsToStandardWithNoRetryOptOut(): void
    {
        $config = $this->baseConfig();

        $this->assertInstanceOf(
            StandardRetryStrategy::class,
            $config->retryStrategy
        );
        $this->assertSame(3, $config->retryStrategy->maxAttempts());
        $this->assertSame(
            NoRetryStrategy::instance(),
            $config->withRetryStrategy(NoRetryStrategy::instance())
                ->retryStrategy
        );
    }

    public function testWithRetryScopeReplacesOnlyRetryScope(): void
    {
        $orig = $this->baseConfig();
        $new = $orig->withRetryScope('client-scope');

        $this->assertSame('client-scope', $new->retryScope);
        $this->assertSame('', $orig->retryScope);
    }

    public function testWithAuthSchemeResolverReplacesOnlyResolver(): void
    {
        $orig = $this->baseConfig();
        $newResolver = new StaticAuthSchemeResolver(['smithy.api#noAuth']);
        $new = $orig->withAuthSchemeResolver($newResolver);

        $this->assertSame($newResolver, $new->authSchemeResolver);
        $this->assertNotSame(
            $orig->authSchemeResolver,
            $new->authSchemeResolver
        );
    }

    public function testWithAuthSchemePreferenceReplacesPreference(): void
    {
        $orig = $this->baseConfig();
        $new = $orig->withAuthSchemePreference([
            'httpBearerAuth',
            'sigv4'
        ]);

        $this->assertSame([], $orig->authSchemePreference);
        $this->assertSame(
            ['httpBearerAuth', 'sigv4'],
            $new->authSchemePreference
        );
    }

    public function testAuthSchemePreferenceRemovesDuplicates(): void
    {
        $config = $this->baseConfig()->withAuthSchemePreference([
            'sigv4',
            'sigv4',
            'httpBearerAuth'
        ]);

        $this->assertSame(
            ['sigv4', 'httpBearerAuth'],
            $config->authSchemePreference
        );
    }

    public function testAuthSchemePreferenceRejectsInvalidNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty strings');

        $this->baseConfig()->withAuthSchemePreference(['sigv4', '']);
    }

    public function testDefaultsToAuthSchemeResolver(): void
    {
        $config = $this->baseConfig();
        $endpoint = new Endpoint('https://a.test');

        $this->assertInstanceOf(
            AuthSchemeResolver::class,
            $config->authSchemeResolver
        );
        $this->assertSame(
            NoAuthScheme::instance(),
            $config->authSchemeResolver->resolveAuthScheme(
                FakeOperation::instance(),
                $config
            )->scheme
        );
    }

    public function testDefaultsToEmptyIdentityResolvers(): void
    {
        $config = $this->baseConfig();

        $this->assertInstanceOf(
            IdentityResolvers::class,
            $config->getIdentityResolvers()
        );
        $this->assertNull(
            $config->getIdentityResolvers()
                ->identityResolver(BearerToken::class)
        );
    }

    public function testWithIdentityResolverRegistersByType(): void
    {
        $provider = new StaticTokenProvider('token');
        $orig = $this->baseConfig();
        $new = $orig->withIdentityResolver(BearerToken::class, $provider);

        $this->assertNull(
            $orig->getIdentityResolvers()
                ->identityResolver(BearerToken::class)
        );
        $this->assertSame(
            $provider,
            $new->getIdentityResolvers()
                ->identityResolver(BearerToken::class)
        );
    }

    public function testIdentityResolversSurviveBaseWithers(): void
    {
        $provider = new StaticTokenProvider('token');
        $config = $this->baseConfig()
            ->withIdentityResolver(BearerToken::class, $provider)
            ->withRetryScope('scope');

        $this->assertSame(
            $provider,
            $config->getIdentityResolvers()
                ->identityResolver(BearerToken::class)
        );
    }

    public function testIndexesSupportedAuthSchemesById(): void
    {
        $scheme = new TestAuthSchemeFixture('smithy.test#auth');
        $config = $this->baseConfig()->withAddedAuthScheme($scheme);

        $this->assertSame(
            $scheme,
            $config->getSupportedAuthScheme('smithy.test#auth')
        );
        $this->assertNull(
            $config->getSupportedAuthScheme('smithy.test#unsupported')
        );
    }

    public function testWithInterceptorAppends(): void
    {
        $orig = $this->baseConfig();
        $a = new RecordingInterceptor('A');
        $b = new RecordingInterceptor('B');

        $new = $orig->withInterceptor($a)->withInterceptor($b);

        $this->assertTrue($orig->interceptorChain->isEmpty());
        $this->assertFalse($new->interceptorChain->isEmpty());
        $this->assertTrue(
            $new->interceptorChain->contains(RecordingInterceptor::class)
        );
        $this->assertNotSame(
            $orig->interceptorChain,
            $new->interceptorChain
        );
    }

    public function testWithPrependedInterceptorPreservesOriginal(): void
    {
        $existing = new IdempotencyTokenInterceptor();
        $prepended = new RecordingInterceptor();
        $orig = $this->baseConfig()->withInterceptor($existing);

        $new = $orig->withPrependedInterceptor($prepended);

        $this->assertFalse(
            $orig->interceptorChain->contains(RecordingInterceptor::class)
        );
        $this->assertTrue(
            $new->interceptorChain->contains(RecordingInterceptor::class)
        );
        $this->assertTrue(
            $new->interceptorChain->contains(
                IdempotencyTokenInterceptor::class
            )
        );
    }

    public function testWithInterceptorsReplaces(): void
    {
        $orig = $this->baseConfig()->withInterceptor(
            new RecordingInterceptor('A')
        );
        $b = new IdempotencyTokenInterceptor();

        $new = $orig->withInterceptors($b);

        $this->assertFalse(
            $new->interceptorChain->contains(RecordingInterceptor::class)
        );
        $this->assertTrue(
            $new->interceptorChain->contains(
                IdempotencyTokenInterceptor::class
            )
        );
    }

    public function testWithAddedAuthSchemeAppendsPreservingNoAuth(): void
    {
        $orig = $this->baseConfig();
        $scheme = new TestAuthSchemeFixture('example#custom');

        $new = $orig->withAddedAuthScheme($scheme);

        // Original has only NoAuthScheme (auto-added).
        $this->assertCount(1, $orig->supportedAuthSchemes);
        $this->assertSame(
            NoAuthScheme::ID,
            $orig->supportedAuthSchemes[0]->getSchemeId()
        );

        // New has NoAuth + the appended custom scheme.
        $ids = \array_map(
            fn(AuthSchemeInterface $s) => $s->getSchemeId(),
            $new->supportedAuthSchemes
        );
        $this->assertContains(NoAuthScheme::ID, $ids);
        $this->assertContains('example#custom', $ids);
    }

    public function testWithSupportedAuthSchemesPreservesNoAuth(): void
    {
        $orig = $this->baseConfig();
        $scheme = new TestAuthSchemeFixture('example#custom');

        $new = $orig->withSupportedAuthSchemes([$scheme]);

        // Constructor re-adds NoAuthScheme even when replacing wholesale.
        $ids = \array_map(
            fn(AuthSchemeInterface $s) => $s->getSchemeId(),
            $new->supportedAuthSchemes
        );
        $this->assertContains(NoAuthScheme::ID, $ids);
        $this->assertContains('example#custom', $ids);
        $this->assertCount(2, $new->supportedAuthSchemes);
    }

    public function testWithContextStoresValueUnderKey(): void
    {
        $orig = $this->baseConfig();

        $new = $orig->withContext('trace-id', 'abc-123');

        $this->assertSame('abc-123', $new->context['trace-id']);
        $this->assertArrayNotHasKey('trace-id', $orig->context);
    }

    public function testWithContextPreservesOtherKeys(): void
    {
        $orig = $this->baseConfig()->withContext('one', 1);

        $new = $orig->withContext('two', 2);

        $this->assertSame(['one' => 1, 'two' => 2], $new->context);
    }

    /**
     * Verify subclass fields survive base-class `withXxx()` calls.
     *
     * This is the reason `copyWith()` exists: the base class uses
     * `new static(...)` and the subclass overrides `copyWith()` once so
     * every inherited `withXxx()` preserves the subclass's own fields.
     */
    public function testSubclassFieldsSurviveBaseClassWithXxx(): void
    {
        $orig = new RegionalClientConfigFixture(
            protocol: new FakeProtocol(),
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            region: 'test-region'
        );

        $transport = new HttpClientTransport(new MockHttpClient());
        $new = $orig->withAuthSchemePreference(['sigv4'])
            ->withRetryScope('scope')
            ->withInterceptor(new RecordingInterceptor())
            ->withContext('foo', 'bar')
            ->withTransport($transport);

        // Every inherited withXxx() call preserves the subclass field.
        $this->assertSame('test-region', $new->region);
        $this->assertSame(['sigv4'], $new->authSchemePreference);
        $this->assertSame($transport, $new->transport);
    }

    public function testSubclassFieldsSurviveWithProtocolId(): void
    {
        $protocol = new FakeProtocol();
        $orig = new RegionalClientConfigFixture(
            protocol: $protocol,
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            protocolResolver: new ProtocolResolver([
                FakeProtocol::ID => $protocol
            ]),
            region: 'test-region'
        );

        $new = $orig
            ->withProtocolId(FakeProtocol::ID)
            ->withService(FakeService::instance())
            ->resolveProtocol();

        self::assertInstanceOf(RegionalClientConfigFixture::class, $new);
        self::assertSame('test-region', $new->region);
        self::assertSame($protocol, $new->getProtocol());
    }

    public function testSubclassFieldsSurviveWithProtocolResolver(): void
    {
        $protocol = new FakeProtocol();
        $orig = new RegionalClientConfigFixture(
            protocol: null,
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            region: 'test-region'
        );

        $new = $orig
            ->withProtocolResolver(new ProtocolResolver([
                FakeProtocol::ID => $protocol
            ]))
            ->withService(FakeService::instance())
            ->resolveProtocol();

        self::assertInstanceOf(RegionalClientConfigFixture::class, $new);
        self::assertSame('test-region', $new->region);
        self::assertSame($protocol, $new->getProtocol());
    }

    /**
     * @return iterable<string, array{ExplicitClientConfigField, \Closure(): object, string}>
     */
    public static function provenanceFields(): iterable
    {
        yield 'retryStrategy' => [
            ExplicitClientConfigField::RetryStrategy,
            static fn() => NoRetryStrategy::instance(),
            'withRetryStrategy'
        ];
        yield 'authSchemeResolver' => [
            ExplicitClientConfigField::AuthSchemeResolver,
            static fn() => new AuthSchemeResolver(),
            'withAuthSchemeResolver'
        ];
        yield 'protocolResolver' => [
            ExplicitClientConfigField::ProtocolResolver,
            static fn() => new ProtocolResolver([
                FakeProtocol::ID => new FakeProtocol()
            ]),
            'withProtocolResolver'
        ];
        yield 'protocol' => [
            ExplicitClientConfigField::Protocol,
            static fn() => new FakeProtocol(),
            'withProtocol'
        ];
    }

    #[DataProvider('provenanceFields')]
    public function testTracksExplicitFields(
        ExplicitClientConfigField $field,
        \Closure $value,
        string $with
    ): void {
        $base = [
            'transport' => new HttpClientTransport(new MockHttpClient()),
            'endpointResolver' => new StaticEndpointResolver(
                new Endpoint('https://a.test')
            )
        ];
        $defaulted = new ClientConfig(...$base);
        $ctorSet = new ClientConfig(...$base, ...[$field->value => $value()]);
        $withSet = $defaulted->{$with}($value());

        self::assertFalse($defaulted->isExplicit($field));
        self::assertTrue($ctorSet->isExplicit($field));
        self::assertTrue($withSet->isExplicit($field));
        // copyWith() passes resolved defaults back to the constructor.
        self::assertFalse(
            $defaulted->withRetryScope('x')->isExplicit($field)
        );
        self::assertTrue($ctorSet->withRetryScope('x')->isExplicit($field));
    }

    #[DataProvider('provenanceFields')]
    public function testSubclassCopiesPreserveProvenance(
        ExplicitClientConfigField $field,
        \Closure $value,
        string $with
    ): void {
        $config = new RegionalClientConfigFixture(
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            region: 'test-region'
        );

        self::assertFalse(
            $config->withRetryScope('x')->isExplicit($field)
        );
        self::assertTrue(
            $config->{$with}($value())->withRetryScope('x')
                ->isExplicit($field)
        );
    }

    public function testResolvedProtocolIsNotExplicit(): void
    {
        $config = new ClientConfig(
            transport: new HttpClientTransport(new MockHttpClient()),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://a.test')
            ),
            protocolResolver: new ProtocolResolver([
                FakeProtocol::ID => new FakeProtocol()
            ])
        );
        $resolved = $config->withService(FakeService::instance())
            ->resolveProtocol();

        self::assertFalse($resolved->isExplicit(ExplicitClientConfigField::Protocol));
        self::assertFalse(
            $resolved->withProtocolId(FakeProtocol::ID)
                ->isExplicit(ExplicitClientConfigField::Protocol)
        );
    }

    private function serviceWithProtocol(string $protocolId): ServiceInterface
    {
        $service = $this->createStub(ServiceInterface::class);
        $service->method('getId')->willReturn('example#Service');
        $service->method('getSchema')->willReturn(new Schema(
            id: 'example#Service',
            type: ShapeType::Service,
            traitsById: [$protocolId => true]
        ));

        return $service;
    }

}
