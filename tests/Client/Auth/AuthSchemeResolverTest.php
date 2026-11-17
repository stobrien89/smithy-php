<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Tests\Client\Auth;

use PHPUnit\Framework\Attributes\{
    CoversClass,
    DataProvider
};
use PHPUnit\Framework\TestCase;
use Smithy\Client\Auth\{
    AuthSchemeOption,
    AuthSchemeResolver,
    NoAuthScheme,
    ResolvedAuthScheme,
    StaticAuthSchemeResolver
};
use Smithy\Client\ClientConfig;
use Smithy\Client\Endpoint\StaticEndpointResolver;
use Smithy\Core\Endpoint\{
    Endpoint,
    EndpointAuthScheme
};
use Smithy\Core\Shape\OperationInterface;
use Smithy\Tests\Client\Fake\{
    FakeProtocol,
    TestAuthSchemeFixture
};
use Smithy\Tests\Utils\Http\MockHttpClient;

#[CoversClass(AuthSchemeResolver::class)]
#[CoversClass(ResolvedAuthScheme::class)]
final class AuthSchemeResolverTest extends TestCase
{
    private const BEARER = 'smithy.api#httpBearerAuth';
    private const NO_AUTH = NoAuthScheme::ID;
    private const SIGV4 = 'aws.auth#sigv4';

    /**
     * @param list<string> $runtimeSupport
     * @param list<string> $modeledSchemes
     * @param list<string> $preference
     */
    #[DataProvider('resolutionCases')]
    public function testResolvesAuthScheme(
        array $runtimeSupport,
        array $modeledSchemes,
        array $preference,
        ?string $expected
    ): void
    {
        $config = $this->config($runtimeSupport);
        $operation = $this->operation($modeledSchemes);

        if ($expected === null) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('auth scheme');
        }

        $resolved = $config->authSchemeResolver->resolveAuthScheme(
            $operation,
            $config,
            $preference
        );

        $this->assertSame($expected, $resolved->scheme->getSchemeId());
    }

    public function testResolvesSeparateSchemeProperties(): void
    {
        $scheme = new TestAuthSchemeFixture(
            self::SIGV4,
            identityProperties: ['identity' => 'default'],
            signerProperties: ['signer' => 'default']
        );
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$scheme]
        );

        $resolved = $config->authSchemeResolver->resolveAuthScheme(
            $this->operation([self::SIGV4]),
            $config
        );

        $this->assertSame(
            ['identity' => 'default'],
            $resolved->identityProperties
        );
        $this->assertSame(
            ['signer' => 'default'],
            $resolved->signerProperties
        );
    }

    public function testPassesClientContextToSchemeProperties(): void
    {
        $scheme = new class implements \Smithy\Client\Auth\AuthSchemeInterface {
            public function getSchemeId(): string
            {
                return 'aws.auth#sigv4';
            }

            /** @param array<string, mixed> $context */
            public function getIdentityProperties(array $context): array
            {
                return ['identityContext' => $context['token'] ?? null];
            }

            public function getIdentityResolver(
                \Smithy\Client\Auth\IdentityResolversInterface $resolvers
            ): ?\Smithy\Client\Auth\IdentityResolverInterface {
                return \Smithy\Client\Auth\AnonymousIdentityResolver::instance();
            }

            /** @param array<string, mixed> $context */
            public function getSignerProperties(array $context): array
            {
                return ['signerContext' => $context['token'] ?? null];
            }

            public function getSigner(): \Smithy\Client\Auth\SignerInterface
            {
                return \Smithy\Client\Auth\NullSigner::instance();
            }
        };
        $config = (new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$scheme]
        ))->withContext('token', 'from-context');

        $resolved = $config->authSchemeResolver->resolveAuthScheme(
            $this->operation([self::SIGV4]),
            $config
        );

        $this->assertSame(
            ['identityContext' => 'from-context'],
            $resolved->identityProperties
        );
        $this->assertSame(
            ['signerContext' => 'from-context'],
            $resolved->signerProperties
        );
    }

    public function testMergesAuthSchemeOptionOverrides(): void
    {
        $scheme = new TestAuthSchemeFixture(
            self::SIGV4,
            identityProperties: [
                'identity' => 'default',
                'shared' => 'default'
            ],
            signerProperties: [
                'signer' => 'default',
                'scope' => 'default'
            ]
        );
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$scheme]
        );
        $resolver = new StaticAuthSchemeResolver([
            new AuthSchemeOption(
                self::SIGV4,
                [
                    'shared' => 'option',
                    'identityOption' => true
                ],
                [
                    'scope' => 'option',
                    'signerOption' => true
                ]
            )
        ]);

        $resolved = $resolver->resolveAuthScheme(
            $this->operation([self::SIGV4]),
            $config
        );

        $this->assertSame(
            [
                'identity' => 'default',
                'shared' => 'option',
                'identityOption' => true
            ],
            $resolved->identityProperties
        );
        $this->assertSame(
            [
                'signer' => 'default',
                'scope' => 'option',
                'signerOption' => true
            ],
            $resolved->signerProperties
        );
    }

    public function testSkipsUnsupportedAuthSchemeOptions(): void
    {
        $scheme = new TestAuthSchemeFixture(
            self::SIGV4,
            identityProperties: ['identity' => 'default']
        );
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$scheme]
        );
        $resolver = new StaticAuthSchemeResolver([
            new AuthSchemeOption(
                'smithy.test#unsupported',
                ['identity' => 'unsupported']
            ),
            new AuthSchemeOption(
                self::SIGV4,
                ['identity' => 'supported']
            )
        ]);

        $resolved = $resolver->resolveAuthScheme(
            $this->operation([self::SIGV4]),
            $config
        );

        $this->assertSame($scheme, $resolved->scheme);
        $this->assertSame(
            ['identity' => 'supported'],
            $resolved->identityProperties
        );
    }

    public function testPreferenceReordersOptionsWithTheirOverrides(): void
    {
        $sigV4 = new TestAuthSchemeFixture(self::SIGV4);
        $bearer = new TestAuthSchemeFixture(self::BEARER);
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$sigV4, $bearer]
        );
        $resolver = new StaticAuthSchemeResolver([
            new AuthSchemeOption(
                self::SIGV4,
                signerPropertyOverrides: ['selected' => 'sigv4']
            ),
            new AuthSchemeOption(
                self::BEARER,
                signerPropertyOverrides: ['selected' => 'bearer']
            )
        ]);

        $resolved = $resolver->resolveAuthScheme(
            $this->operation([self::SIGV4, self::BEARER]),
            $config,
            ['httpBearerAuth']
        );

        $this->assertSame($bearer, $resolved->scheme);
        $this->assertSame(
            ['selected' => 'bearer'],
            $resolved->signerProperties
        );
    }

    public function testEndpointPropertiesOverrideOnlySignerProperties(): void
    {
        $scheme = new TestAuthSchemeFixture(
            self::SIGV4,
            identityProperties: [
                'identity' => 'default',
                'shared' => 'identity'
            ],
            signerProperties: [
                'signer' => 'default',
                'scope' => 'default'
            ]
        );
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$scheme]
        );
        $operation = $this->operation([self::SIGV4]);
        $resolved = $config->authSchemeResolver->resolveAuthScheme(
            $operation,
            $config
        );

        $endpointResolved = $config
            ->authSchemeResolver
            ->resolveEndpointAuthScheme(
                $resolved,
                $operation,
                $config,
                [
                    new EndpointAuthScheme(
                        self::SIGV4,
                        ['scope' => 'endpoint']
                    )
                ]
            );

        $this->assertSame($scheme, $endpointResolved->scheme);
        $this->assertSame(
            [
                'identity' => 'default',
                'shared' => 'identity'
            ],
            $endpointResolved->identityProperties
        );
        $this->assertSame(
            [
                'signer' => 'default',
                'scope' => 'endpoint'
            ],
            $endpointResolved->signerProperties
        );
    }

    public function testEndpointReplacementLoadsSchemeDefaults(): void
    {
        $modeledScheme = new TestAuthSchemeFixture(
            self::SIGV4,
            identityProperties: ['identity' => 'modeled'],
            signerProperties: ['signer' => 'modeled']
        );
        $endpointScheme = new TestAuthSchemeFixture(
            self::BEARER,
            identityProperties: ['identity' => 'endpoint'],
            signerProperties: [
                'signer' => 'endpoint',
                'scope' => 'default'
            ]
        );
        $config = new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: [$modeledScheme, $endpointScheme]
        );
        $operation = $this->operation([self::SIGV4]);
        $resolved = $config->authSchemeResolver->resolveAuthScheme(
            $operation,
            $config
        );

        $endpointResolved = $config
            ->authSchemeResolver
            ->resolveEndpointAuthScheme(
                $resolved,
                $operation,
                $config,
                [
                    new EndpointAuthScheme(self::SIGV4),
                    new EndpointAuthScheme(
                        self::BEARER,
                        ['scope' => 'endpoint']
                    )
                ],
                ['httpBearerAuth']
            );

        $this->assertSame($endpointScheme, $endpointResolved->scheme);
        $this->assertSame(
            ['identity' => 'endpoint'],
            $endpointResolved->identityProperties
        );
        $this->assertSame(
            [
                'signer' => 'endpoint',
                'scope' => 'endpoint'
            ],
            $endpointResolved->signerProperties
        );
    }

    /**
     * @return array<string, array{
     *     list<string>,
     *     list<string>,
     *     list<string>,
     *     string|null
     * }>
     */
    public static function resolutionCases(): array
    {
        return [
            'modeled order selects sigv4' => [
                [self::SIGV4, self::BEARER],
                [self::SIGV4, self::BEARER],
                [],
                self::SIGV4
            ],
            'modeled order selects bearer' => [
                [self::SIGV4, self::BEARER],
                [self::BEARER, self::SIGV4],
                [],
                self::BEARER
            ],
            'unsupported leading scheme is skipped' => [
                [self::SIGV4],
                [self::BEARER, self::SIGV4],
                [],
                self::SIGV4
            ],
            'no auth remains available' => [
                [self::SIGV4],
                [self::NO_AUTH],
                [],
                self::NO_AUTH
            ],
            'no supported modeled scheme fails' => [
                [self::SIGV4],
                [self::BEARER],
                [],
                null
            ],
            'preference reorders modeled auth' => [
                [self::SIGV4, self::BEARER],
                [self::SIGV4, self::BEARER],
                ['httpBearerAuth'],
                self::BEARER
            ],
            'preference cannot add modeled auth' => [
                [self::SIGV4, self::BEARER],
                [self::SIGV4],
                ['httpBearerAuth'],
                self::SIGV4
            ],
            'unknown preference preserves modeled order' => [
                [self::SIGV4, self::BEARER],
                [self::SIGV4, self::BEARER],
                ['sigv3'],
                self::SIGV4
            ]
        ];
    }

    /**
     * @param list<string> $supportedSchemeIds
     */
    private function config(array $supportedSchemeIds): ClientConfig
    {
        $schemes = [];
        foreach ($supportedSchemeIds as $schemeId) {
            $schemes[] = new TestAuthSchemeFixture($schemeId);
        }

        return new ClientConfig(
            protocol: new FakeProtocol(),
            transport: new \Smithy\Http\Client\HttpClientTransport(
                new MockHttpClient()
            ),
            endpointResolver: new StaticEndpointResolver(
                new Endpoint('https://example.test')
            ),
            supportedAuthSchemes: $schemes
        );
    }

    /**
     * @param list<string> $effectiveAuthSchemes
     */
    private function operation(array $effectiveAuthSchemes): OperationInterface
    {
        $operation = $this->createStub(OperationInterface::class);
        $operation->method('getName')->willReturn('TestOperation');
        $operation
            ->method('getEffectiveAuthSchemes')
            ->willReturn($effectiveAuthSchemes);

        return $operation;
    }
}
