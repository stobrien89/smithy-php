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
    AnonymousIdentityResolver,
    IdentityResolvers,
    NoAuthScheme,
    NullSigner
};

#[CoversClass(NoAuthScheme::class)]
final class NoAuthSchemeTest extends TestCase
{
    public function testReturnsAnonymousAuthComponents(): void
    {
        $scheme = NoAuthScheme::instance();

        self::assertSame($scheme, NoAuthScheme::instance());
        self::assertSame(NoAuthScheme::ID, $scheme->getSchemeId());
        self::assertSame([], $scheme->getIdentityProperties([]));
        self::assertSame([], $scheme->getSignerProperties([]));
        self::assertSame(
            AnonymousIdentityResolver::instance(),
            $scheme->getIdentityResolver(new IdentityResolvers())
        );
        self::assertSame(NullSigner::instance(), $scheme->getSigner());
    }
}
