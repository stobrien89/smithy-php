<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Named authentication scheme: identity resolver plus signer.
 *
 * An {@see AuthSchemeResolverInterface} selects the scheme. The client
 * pipeline then calls `getIdentityResolver()` and `getSigner()` on the
 * resolved scheme. The scheme wires its resolver either from its own
 * configuration or from the supplied {@see IdentityResolversInterface}.
 *
 * The property methods receive the client `context` bag (the array built
 * by `ClientConfig::withContext()`), so an auth scheme depends only on the
 * context and the identity resolver repository.
 *
 * Scheme IDs follow Smithy convention: `smithy.api#httpBearerAuth`,
 * `smithy.api#noAuth`, and vendor-defined scheme IDs.
 */
interface AuthSchemeInterface
{
    /**
     * Return the Smithy shape ID of this auth scheme.
     */
    public function getSchemeId(): string;

    /**
     * Return default properties used to resolve an identity.
     *
     * @param array<string, mixed> $context Client context bag.
     * @return array<string, mixed>
     */
    public function getIdentityProperties(array $context): array;

    /**
     * Return the identity resolver for this scheme, or null when the scheme's
     * identity resolver is unavailable from the supplied repository.
     *
     * A null return marks the scheme unusable for the request; the resolver
     * then moves on to the next option.
     */
    public function getIdentityResolver(
        IdentityResolversInterface $resolvers
    ): ?IdentityResolverInterface;

    /**
     * Return default properties used to sign a request.
     *
     * @param array<string, mixed> $context Client context bag.
     * @return array<string, mixed>
     */
    public function getSignerProperties(array $context): array;

    /**
     * Return the signer for this scheme.
     */
    public function getSigner(): SignerInterface;
}
