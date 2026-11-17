<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Core\Endpoint\EndpointAuthScheme;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve the supported auth scheme used for an operation.
 *
 * The default implementation uses the operation's effective auth schemes.
 * Subclasses may override {@see resolveAuthSchemeOptions()} to provide
 * another ordered source of candidates and property overrides.
 */
class AuthSchemeResolver implements AuthSchemeResolverInterface
{
    /**
     * Resolve the first supported auth scheme for an operation.
     *
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no scheme can be resolved.
     */
    final public function resolveAuthScheme(
        OperationInterface $operation,
        ClientConfig $config,
        array $preference = []
    ): ResolvedAuthScheme
    {
        $options = $this->resolveAuthSchemeOptions($operation, $config);
        foreach (self::prioritizeOptions(
            $options,
            $preference
        ) as $option) {
            $scheme = $config->getSupportedAuthScheme($option->schemeId);
            if ($scheme !== null) {
                return self::resolveOption($scheme, $option, $config);
            }
        }

        throw new \RuntimeException(
            'No supported auth scheme is available for operation '
            . $operation->getName()
            . '.'
        );
    }

    /**
     * Reconcile a resolved auth scheme with endpoint auth options.
     *
     * @param list<EndpointAuthScheme> $endpointAuthSchemes
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no endpoint scheme can be resolved.
     */
    final public function resolveEndpointAuthScheme(
        ResolvedAuthScheme $resolvedAuthScheme,
        OperationInterface $operation,
        ClientConfig $config,
        array $endpointAuthSchemes,
        array $preference = []
    ): ResolvedAuthScheme
    {
        if ($endpointAuthSchemes === []) {
            return $resolvedAuthScheme;
        }

        $options = [];
        foreach ($endpointAuthSchemes as $endpointScheme) {
            $options[] = new AuthSchemeOption(
                $endpointScheme->schemeId,
                signerPropertyOverrides: $endpointScheme->properties
            );
        }

        foreach (self::prioritizeOptions(
            $options,
            $preference
        ) as $option) {
            $scheme = $config->getSupportedAuthScheme(
                $option->schemeId
            );
            if ($scheme === null) {
                continue;
            }

            if ($scheme === $resolvedAuthScheme->scheme) {
                return new ResolvedAuthScheme(
                    $scheme,
                    $resolvedAuthScheme->identityProperties,
                    [
                        ...$resolvedAuthScheme->signerProperties,
                        ...$option->signerPropertyOverrides
                    ]
                );
            }

            return self::resolveOption($scheme, $option, $config);
        }

        $requested = [];
        foreach ($endpointAuthSchemes as $endpointScheme) {
            $requested[] = $endpointScheme->schemeId;
        }

        throw new \RuntimeException(
            'Endpoint resolution requested unsupported auth schemes ['
            . \implode(', ', $requested)
            . '] for operation '
            . $operation->getName()
            . '.'
        );
    }

    /**
     * Return auth scheme options in priority order.
     *
     * @return list<AuthSchemeOption>
     */
    protected function resolveAuthSchemeOptions(
        OperationInterface $operation,
        ClientConfig $config
    ): array
    {
        $options = [];
        foreach ($operation->getEffectiveAuthSchemes() as $schemeId) {
            $options[] = new AuthSchemeOption($schemeId);
        }

        return $options;
    }

    /**
     * Prioritize auth scheme options without losing duplicate options.
     *
     * @param list<AuthSchemeOption> $options
     * @param list<string> $preference
     *
     * @return list<AuthSchemeOption>
     */
    private static function prioritizeOptions(
        array $options,
        array $preference
    ): array
    {
        $candidates = [];
        $seen = [];
        foreach ($preference as $preferredName) {
            foreach ($options as $index => $option) {
                if (!isset($seen[$index])
                    && self::authSchemeName($option->schemeId)
                        === $preferredName
                ) {
                    $candidates[] = $option;
                    $seen[$index] = true;
                }
            }
        }

        foreach ($options as $index => $option) {
            if (!isset($seen[$index])) {
                $candidates[] = $option;
            }
        }

        return $candidates;
    }

    /**
     * Resolve a supported option with scheme defaults and option overrides.
     */
    private static function resolveOption(
        AuthSchemeInterface $scheme,
        AuthSchemeOption $option,
        ClientConfig $config
    ): ResolvedAuthScheme
    {
        return new ResolvedAuthScheme(
            $scheme,
            [
                ...$scheme->getIdentityProperties($config->context),
                ...$option->identityPropertyOverrides
            ],
            [
                ...$scheme->getSignerProperties($config->context),
                ...$option->signerPropertyOverrides
            ]
        );
    }

    private static function authSchemeName(string $schemeId): string
    {
        $separator = \strrpos($schemeId, '#');

        return $separator === false
            ? $schemeId
            : \substr($schemeId, $separator + 1);
    }
}
