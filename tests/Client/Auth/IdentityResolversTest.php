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
    IdentityResolvers,
    StaticTokenProvider
};

#[CoversClass(IdentityResolvers::class)]
final class IdentityResolversTest extends TestCase
{
    public function testResolvesRegisteredIdentityType(): void
    {
        $provider = new StaticTokenProvider('token');
        $resolvers = new IdentityResolvers([
            BearerToken::class => $provider
        ]);

        self::assertSame(
            $provider,
            $resolvers->identityResolver(BearerToken::class)
        );
    }

    public function testReturnsNullForUnregisteredIdentityType(): void
    {
        self::assertNull(
            (new IdentityResolvers())->identityResolver(BearerToken::class)
        );
    }

    public function testWithRegistersWithoutMutatingOriginal(): void
    {
        $provider = new StaticTokenProvider('token');
        $base = new IdentityResolvers();
        $extended = $base->with(BearerToken::class, $provider);

        self::assertNull($base->identityResolver(BearerToken::class));
        self::assertSame(
            $provider,
            $extended->identityResolver(BearerToken::class)
        );
    }
}
