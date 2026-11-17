<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Default {@see IdentityResolversInterface}: a map from identity type to
 * the resolver that produces it.
 *
 * The identity class is supplied explicitly at registration, since each
 * resolver's identity type is declared by the caller.
 */
final class IdentityResolvers implements IdentityResolversInterface
{
    /** @var array<class-string<IdentityInterface>, IdentityResolverInterface> */
    private array $resolvers;

    /**
     * @param array<class-string<IdentityInterface>, IdentityResolverInterface> $resolvers
     *        Resolvers keyed by the identity type they resolve.
     */
    public function __construct(array $resolvers = [])
    {
        $this->resolvers = $resolvers;
    }

    /**
     * Return a copy with `$resolver` registered for `$identityClass`.
     *
     * @param class-string<IdentityInterface> $identityClass
     */
    public function with(
        string $identityClass,
        IdentityResolverInterface $resolver
    ): self
    {
        return new self(
            [...$this->resolvers, $identityClass => $resolver]
        );
    }

    /** @inheritDoc */
    public function identityResolver(
        string $identityClass
    ): ?IdentityResolverInterface
    {
        return $this->resolvers[$identityClass] ?? null;
    }
}
