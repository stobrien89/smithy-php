<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Provide the Smithy HTTP bearer authentication scheme.
 */
final readonly class HttpBearerAuthScheme implements AuthSchemeInterface
{
    public const ID = 'smithy.api#httpBearerAuth';

    /**
     * Create an HTTP bearer auth scheme.
     *
     * When `$tokenProvider` is null the scheme looks up a {@see BearerToken}
     * resolver from the {@see IdentityResolversInterface} supplied at
     * resolution time, mirroring smithy-java.
     */
    public function __construct(
        private ?IdentityResolverInterface $tokenProvider = null
    ) {}

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
        return $this->tokenProvider
            ?? $resolvers->identityResolver(BearerToken::class);
    }

    /** @inheritDoc */
    public function getSignerProperties(array $context): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getSigner(): SignerInterface
    {
        return HttpBearerAuthSigner::instance();
    }
}
