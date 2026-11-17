<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Look up an {@see IdentityResolverInterface} by the identity type it
 * resolves.
 *
 * An auth scheme asks this repository for the resolver that produces its
 * identity (for example, a `BearerToken` resolver for HTTP bearer auth).
 * When the identity type has a registered resolver, {@see identityResolver()}
 * returns it; otherwise it returns null, which marks that scheme unusable so
 * selection moves on to the next option.
 *
 * Mirrors smithy-java's `IdentityResolvers`. Implementations depend only on
 * the identity type and the resolvers they hold.
 */
interface IdentityResolversInterface
{
    /**
     * Return the resolver registered for an identity type, or null.
     *
     * @param class-string<IdentityInterface> $identityClass
     */
    public function identityResolver(
        string $identityClass
    ): ?IdentityResolverInterface;
}
