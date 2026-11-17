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
 * A scheme whose identity type has no registered resolver is not usable;
 * {@see identityResolver()} returns null in that case.
 *
 * Mirrors smithy-java's `IdentityResolvers`. Implementations must not
 * depend on `ClientConfig`.
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
