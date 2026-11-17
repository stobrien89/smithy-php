<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Tests\Client\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Smithy\Client\Auth\{
    BearerToken,
    HttpBearerAuthScheme,
    HttpBearerAuthSigner,
    IdentityResolvers,
    StaticTokenProvider
};

#[CoversClass(HttpBearerAuthScheme::class)]
final class HttpBearerAuthSchemeTest extends TestCase
{
    public function testReturnsConfiguredProviderAndSigner(): void
    {
        $provider = new StaticTokenProvider('token', 123.0);
        $scheme = new HttpBearerAuthScheme($provider);

        $identity = $scheme
            ->getIdentityResolver(new IdentityResolvers())
            ->resolveIdentity()
            ->unwrap();

        self::assertSame(HttpBearerAuthScheme::ID, $scheme->getSchemeId());
        self::assertInstanceOf(BearerToken::class, $identity);
        self::assertSame(123.0, $identity->getExpirationTime());
        self::assertSame(
            HttpBearerAuthSigner::instance(),
            $scheme->getSigner()
        );
    }

    public function testFallsBackToResolversRepositoryForBearerToken(): void
    {
        $provider = new StaticTokenProvider('token', 123.0);
        $scheme = new HttpBearerAuthScheme();
        $resolvers = (new IdentityResolvers())
            ->with(BearerToken::class, $provider);

        self::assertSame(
            $provider,
            $scheme->getIdentityResolver($resolvers)
        );
    }

    public function testReturnsNullWhenNoProviderAndNoResolver(): void
    {
        $scheme = new HttpBearerAuthScheme();

        self::assertNull(
            $scheme->getIdentityResolver(new IdentityResolvers())
        );
    }
}
