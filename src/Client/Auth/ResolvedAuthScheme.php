<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Selected auth scheme and its effective identity and signer properties.
 *
 * Carries the identity resolver the scheme produced during selection so the
 * pipeline reuses it directly.
 */
final readonly class ResolvedAuthScheme
{
    /**
     * @param array<string, mixed> $identityProperties
     * @param array<string, mixed> $signerProperties
     */
    public function __construct(
        public AuthSchemeInterface $scheme,
        public array $identityProperties = [],
        public array $signerProperties = [],
        public ?IdentityResolverInterface $identityResolver = null
    ) {}
}
