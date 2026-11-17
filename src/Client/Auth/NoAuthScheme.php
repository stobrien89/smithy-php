<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * The `smithy.api#noAuth` scheme: anonymous identity plus null signer.
 *
 * Always present in the client's supported schemes so operations tagged
 * `@auth([])` or `@optionalAuth` can resolve without further setup.
 */
final class NoAuthScheme implements AuthSchemeInterface
{
    public const ID = 'smithy.api#noAuth';

    private static ?self $instance = null;

    /**
     * Return the shared no-auth scheme.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    /** @inheritDoc */
    public function getSchemeId(): string
    {
        return self::ID;
    }

    /** @inheritDoc */
    public function getIdentityProperties(array $context): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getIdentityResolver(
        IdentityResolversInterface $resolvers
    ): ?IdentityResolverInterface
    {
        return AnonymousIdentityResolver::instance();
    }

    /** @inheritDoc */
    public function getSignerProperties(array $context): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getSigner(): SignerInterface
    {
        return NullSigner::instance();
    }
}
