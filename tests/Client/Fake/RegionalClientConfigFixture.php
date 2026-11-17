<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Tests\Client\Fake;

use Smithy\Client\Auth\{
    AuthSchemeInterface,
    AuthSchemeResolverInterface,
    IdentityResolversInterface
};
use Smithy\Client\ClientConfig;
use Smithy\Client\Endpoint\EndpointResolverInterface;
use Smithy\Client\Interceptor\InterceptorChain;
use Smithy\Client\Plugin\PluginState;
use Smithy\Client\Protocol\ProtocolResolverInterface;
use Smithy\Client\Retry\RetryStrategyInterface;
use Smithy\Core\Transport\TransportInterface;
use Smithy\Core\Shape\ServiceInterface;
use Smithy\Protocol\ProtocolInterface;

/**
 * Client config fixture with one subclass-owned field.
 */
final class RegionalClientConfigFixture extends ClientConfig
{
    /**
     * @param ProtocolInterface|null $protocol
     * @param list<AuthSchemeInterface> $supportedAuthSchemes
     * @param list<string> $authSchemePreference
     * @param array<string, mixed> $context
     * @param array<string, bool>|null $explicitFields
     */
    public function __construct(
        TransportInterface $transport,
        EndpointResolverInterface $endpointResolver,
        ?ProtocolInterface $protocol = null,
        ?ProtocolResolverInterface $protocolResolver = null,
        ?string $protocolId = null,
        InterceptorChain $interceptors = new InterceptorChain(),
        array $supportedAuthSchemes = [],
        ?AuthSchemeResolverInterface $authSchemeResolver = null,
        ?RetryStrategyInterface $retryStrategy = null,
        string $retryScope = '',
        array $context = [],
        array $authSchemePreference = [],
        public readonly string $region = '',
        ?ServiceInterface $service = null,
        ?PluginState $pluginState = null,
        ?bool $protocolIsExplicit = null,
        ?array $explicitFields = null,
        ?IdentityResolversInterface $identityResolvers = null
    )
    {
        parent::__construct(
            transport: $transport,
            endpointResolver: $endpointResolver,
            protocol: $protocol,
            protocolResolver: $protocolResolver,
            protocolId: $protocolId,
            interceptors: $interceptors,
            supportedAuthSchemes: $supportedAuthSchemes,
            authSchemeResolver: $authSchemeResolver,
            retryStrategy: $retryStrategy,
            retryScope: $retryScope,
            context: $context,
            authSchemePreference: $authSchemePreference,
            service: $service,
            pluginState: $pluginState,
            protocolIsExplicit: $protocolIsExplicit,
            explicitFields: $explicitFields,
            identityResolvers: $identityResolvers
        );
    }

    /** @inheritDoc */
    protected function copyWith(array $overrides): static
    {
        return new static(
            transport: $overrides['transport'] ?? $this->transport,
            endpointResolver: $overrides['endpointResolver']
                ?? $this->endpointResolver,
            protocol: \array_key_exists('protocol', $overrides)
                ? $overrides['protocol']
                : $this->protocol,
            protocolResolver: $overrides['protocolResolver']
                ?? $this->protocolResolver,
            protocolId: \array_key_exists('protocolId', $overrides)
                ? $overrides['protocolId']
                : $this->protocolId,
            interceptors: $overrides['interceptors']
                ?? $this->interceptorChain,
            supportedAuthSchemes: $overrides['supportedAuthSchemes']
                ?? $this->supportedAuthSchemes,
            authSchemeResolver: $overrides['authSchemeResolver']
                ?? $this->authSchemeResolver,
            retryStrategy: $overrides['retryStrategy']
                ?? $this->retryStrategy,
            retryScope: $overrides['retryScope'] ?? $this->retryScope,
            context: $overrides['context'] ?? $this->context,
            authSchemePreference: $overrides['authSchemePreference']
                ?? $this->authSchemePreference,
            region: $overrides['region'] ?? $this->region,
            service: $overrides['service'] ?? $this->service,
            pluginState: $overrides['pluginState']
                ?? $this->getPluginState(),
            protocolIsExplicit: $overrides['protocolIsExplicit']
                ?? $this->hasExplicitProtocol(),
            explicitFields: $this->explicitFieldsFor($overrides),
            identityResolvers: $overrides['identityResolvers']
                ?? $this->identityResolvers
        );
        );
    }
}
