<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Client\Auth\AuthSchemeInterface;
use Smithy\Client\Auth\AuthSchemeResolver;
use Smithy\Client\Auth\AuthSchemeResolverInterface;
use Smithy\Client\Auth\IdentityResolverInterface;
use Smithy\Client\Auth\IdentityResolvers;
use Smithy\Client\Auth\IdentityResolversInterface;
use Smithy\Client\Auth\NoAuthScheme;
use Smithy\Client\Endpoint\EndpointResolverInterface;
use Smithy\Client\Interceptor\InterceptorChain;
use Smithy\Client\Interceptor\InterceptorInterface;
use Smithy\Client\Plugin\{
    AutoPlugin,
    PluginCollection,
    PluginInterface,
    PluginResolver,
    PluginState
};
use Smithy\Client\Protocol\{
    ProtocolResolver,
    ProtocolResolverInterface
};
use Smithy\Client\Retry\NoRetryStrategy;
use Smithy\Client\Retry\RetryStrategyInterface;
use Smithy\Client\Retry\StandardRetryStrategy;
use Smithy\Core\Transport\TransportInterface;
use Smithy\Core\Shape\ServiceInterface;
use Smithy\Protocol\ProtocolInterface;

/**
 * Immutable configuration for a {@see ClientInterface}.
 *
 * Holds every dependency the pipeline needs to execute an operation:
 * protocol, transport, endpoint resolver, retry strategy, auth
 * schemes, and interceptors. `withXxx()` helpers return copies.
 *
 * The `context` associative bag stores extension state for
 * interceptors and generated client config subclasses.
 */
class ClientConfig
{
    private readonly PluginState $pluginState;

    /** @var array<string, bool> Keyed by {@see ExplicitClientConfigField} value. */
    private readonly array $explicitFields;

    public readonly InterceptorChain $interceptorChain;

    /** @var list<AuthSchemeInterface> */
    public readonly array $supportedAuthSchemes;

    /** @var array<string, AuthSchemeInterface> */
    private readonly array $supportedAuthSchemesById;

    /** @var list<string> */
    public readonly array $authSchemePreference;

    public readonly AuthSchemeResolverInterface $authSchemeResolver;

    public readonly IdentityResolversInterface $identityResolvers;

    public readonly RetryStrategyInterface $retryStrategy;

    public readonly string $retryScope;

    public readonly TransportInterface $transport;

    public readonly ProtocolResolverInterface $protocolResolver;

    /** @var array<string, mixed> */
    public readonly array $context;

    /**
     * @param TransportInterface $transport Transport for serialized protocol
     *                                     messages.
     * @param EndpointResolverInterface $endpointResolver Resolves the
     *                                  endpoint once per operation execution.
     * @param ProtocolInterface|null $protocol Explicit protocol override.
     * @param ProtocolResolverInterface|null $protocolResolver Selects a
     *     modeled protocol when no explicit implementation is configured.
     * @param string|null $protocolId Explicit modeled protocol shape ID.
     * @param InterceptorChain $interceptors Interceptors invoked at each
     *                                      pipeline hook point.
     * @param list<AuthSchemeInterface> $supportedAuthSchemes Auth schemes
     *                                  the resolver may select from.
     * @param AuthSchemeResolverInterface|null $authSchemeResolver
     *                                         Selects a scheme per operation.
     * @param IdentityResolversInterface|null $identityResolvers Repository of
     *        identity resolvers keyed by identity type, queried by auth
     *        schemes. Defaults to an empty {@see IdentityResolvers}.
     * @param list<string> $authSchemePreference Auth scheme names in
     *        customer-preferred order.
     * @param RetryStrategyInterface|null $retryStrategy Defaults to a new
     *                                    {@see StandardRetryStrategy}; pass
     *                                    {@see NoRetryStrategy::instance()}
     *                                    to disable retries.
     * @param string $retryScope Retry scope identifier shared across
     *               executions.
     * @param array<string, mixed> $context Extension bag for
     *                      interceptor state or forward-compatible fields.
     * @param ServiceInterface|null $service Runtime service metadata.
     * @param PluginState|null $pluginState Internal plugin application state.
     * @param bool|null $protocolIsExplicit Internal protocol provenance.
     *                  Takes precedence over `$explicitFields['protocol']`.
     * @param array<string, bool>|null $explicitFields Internal provenance
     *        map threaded through {@see copyWith()}. Fields absent from
     *        the map are explicit when their argument is non-null.
     */
    public function __construct(
        TransportInterface $transport,
        public readonly EndpointResolverInterface $endpointResolver,
        public readonly ?ProtocolInterface $protocol = null,
        ?ProtocolResolverInterface $protocolResolver = null,
        public readonly ?string $protocolId = null,
        InterceptorChain $interceptors = new InterceptorChain(),
        array $supportedAuthSchemes = [],
        ?AuthSchemeResolverInterface $authSchemeResolver = null,
        ?RetryStrategyInterface $retryStrategy = null,
        string $retryScope = '',
        array $context = [],
        array $authSchemePreference = [],
        public readonly ?ServiceInterface $service = null,
        ?PluginState $pluginState = null,
        ?bool $protocolIsExplicit = null,
        ?array $explicitFields = null,
        ?IdentityResolversInterface $identityResolvers = null
    )
    {
        if ($protocolId === '') {
            throw new \InvalidArgumentException(
                'Protocol ID must be a non-empty shape ID.'
            );
        }
        if ($protocol !== null
            && $protocolId !== null
            && $protocol->getId() !== $protocolId
        ) {
            throw new \InvalidArgumentException(
                "Protocol implementation {$protocol->getId()} does not match "
                . "configured protocol {$protocolId}."
            );
        }
        if ($protocol !== null
            && !$protocol->getMessageExchange()->isCompatibleWith(
                $transport->getMessageExchange()
            )
        ) {
            throw new \InvalidArgumentException(
                'Protocol and transport message exchanges are incompatible.'
            );
        }

        $this->transport = $transport;
        $this->protocolResolver =
            $protocolResolver ?? ProtocolResolver::defaults();
        $explicitFields ??= [];
        $fields = [];
        foreach (ExplicitClientConfigField::cases() as $field) {
            $fields[$field->value] = $explicitFields[$field->value]
                ?? match ($field) {
                    ExplicitClientConfigField::Protocol => $protocol,
                    ExplicitClientConfigField::ProtocolResolver => $protocolResolver,
                    ExplicitClientConfigField::AuthSchemeResolver =>
                        $authSchemeResolver,
                    ExplicitClientConfigField::RetryStrategy => $retryStrategy
                } !== null;
        }
        if ($protocolIsExplicit !== null) {
            $fields[ExplicitClientConfigField::Protocol->value] = $protocolIsExplicit;
        }
        $this->explicitFields = $fields;
        $this->interceptorChain = $interceptors;

        $schemes = \array_values($supportedAuthSchemes);
        $hasNoAuth = false;
        foreach ($schemes as $s) {
            if ($s->getSchemeId() === NoAuthScheme::ID) {
                $hasNoAuth = true;
                break;
            }
        }
        if (!$hasNoAuth) {
            $schemes[] = NoAuthScheme::instance();
        }
        $this->supportedAuthSchemes = $schemes;
        $supportedAuthSchemesById = [];
        foreach ($schemes as $scheme) {
            $supportedAuthSchemesById[$scheme->getSchemeId()] = $scheme;
        }
        $this->supportedAuthSchemesById = $supportedAuthSchemesById;
        $this->authSchemePreference = self::validateAuthSchemePreference(
            $authSchemePreference
        );

        $this->authSchemeResolver = $authSchemeResolver
            ?? new AuthSchemeResolver();
        $this->identityResolvers = $identityResolvers
            ?? new IdentityResolvers();
        $this->retryStrategy = $retryStrategy ?? new StandardRetryStrategy();
        $this->retryScope = $retryScope;
        $this->context = $context;
        $this->pluginState = $pluginState ?? PluginState::defaults();
    }

    /**
     * Register a plugin for application when the client is constructed.
     */
    public function withPlugin(PluginInterface $plugin): static
    {
        return $this->withPlugins($plugin);
    }

    /**
     * Register plugins for application when the client is constructed.
     */
    public function withPlugins(PluginInterface ...$plugins): static
    {
        return $this->withPluginCollection(
            new PluginCollection(...$plugins)
        );
    }

    /**
     * Register a collection for application when the client is constructed.
     */
    public function withPluginCollection(
        PluginCollection $plugins
    ): static {
        if ($plugins->isEmpty()) {
            return $this;
        }

        $state = $this->pluginState->withPlugins($plugins);

        return $state === $this->pluginState
            ? $this
            : $this->withResolvedPluginState($state);
    }

    /**
     * Resolve and apply every registered plugin.
     *
     * @internal Called after a client attaches its service metadata.
     */
    public function resolvePlugins(): static
    {
        /** @var static */
        return (new PluginResolver())
            ->resolve(new PluginCollection(), $this->pluginState)
            ->apply($this);
    }

    /**
     * Replace the predicate used to select plugins.
     *
     * Previously applied plugins remain applied. Registered plugins that were
     * previously rejected are reconsidered.
     *
     * @param callable(PluginInterface): bool $predicate
     */
    public function withPluginPredicate(callable $predicate): static
    {
        return $this->resolveWithPluginPredicate(
            self::toClosure($predicate)
        );
    }

    /**
     * Combine a predicate with the currently configured predicate.
     *
     * @param callable(PluginInterface): bool $predicate
     */
    public function withAddedPluginPredicate(callable $predicate): static
    {
        $added = self::toClosure($predicate);
        $existing = $this->pluginState->predicate;

        return $this->resolveWithPluginPredicate(
            $existing === null
                ? $added
                : static fn(PluginInterface $plugin): bool =>
                    $existing($plugin) && $added($plugin)
        );
    }

    /**
     * Disable Composer-registered automatic plugins.
     *
     * Automatic plugins already applied to this configuration are not
     * removed.
     */
    public function withoutAutoPlugins(): static
    {
        return $this->withAddedPluginPredicate(
            static fn(PluginInterface $plugin): bool =>
                $plugin !== AutoPlugin::instance()
        );
    }

    /**
     * Return internal plugin application state.
     *
     * @internal
     */
    public function getPluginState(): PluginState
    {
        return $this->pluginState;
    }

    /**
     * Return a config carrying resolved plugin state.
     *
     * @internal
     */
    public function withResolvedPluginState(PluginState $state): static
    {
        if ($state === $this->pluginState) {
            return $this;
        }

        return $this->copyWith(['pluginState' => $state]);
    }

    /**
     * Create a config associated with runtime service metadata.
     */
    public function withService(ServiceInterface $service): static
    {
        if ($service === $this->service) {
            return $this;
        }

        return $this->copyWith(['service' => $service]);
    }

    /**
     * Resolve the configured protocol from the attached service schema.
     *
     * @internal Called while constructing a client.
     */
    public function resolveProtocol(): static
    {
        if ($this->service === null) {
            throw new \LogicException(
                'Client service metadata must be configured before protocol '
                . 'resolution.'
            );
        }

        $protocol = $this->protocolResolver->resolveProtocol(
            $this->service,
            $this->protocolId,
            $this->hasExplicitProtocol() ? $this->protocol : null
        );
        if ($protocol === $this->protocol) {
            return $this;
        }

        return $this->copyWith([
            'protocol' => $protocol,
            'protocolIsExplicit' => false
        ]);
    }

    /**
     * Create a config with `$interceptor` appended.
     */
    public function withInterceptor(InterceptorInterface $interceptor): static
    {
        return $this->copyWith([
            'interceptors' => $this->interceptorChain->add($interceptor)
        ]);
    }

    /**
     * Create a config with `$interceptor` prepended.
     */
    public function withPrependedInterceptor(
        InterceptorInterface $interceptor
    ): static
    {
        return $this->copyWith([
            'interceptors' => $this->interceptorChain->prepend($interceptor)
        ]);
    }

    /**
     * Create a config with the interceptors replaced.
     */
    public function withInterceptors(
        InterceptorInterface ...$interceptors
    ): static
    {
        return $this->copyWith([
            'interceptors' => new InterceptorChain(...$interceptors)
        ]);
    }

    /**
     * Create a config with the retry strategy replaced.
     */
    public function withRetryStrategy(RetryStrategyInterface $strategy): static
    {
        return $this->copyWith(['retryStrategy' => $strategy]);
    }

    /**
     * Create a config with the retry scope string replaced.
     *
     * The retry scope is passed to the {@see RetryStrategyInterface} so
     * that multiple executions with the same scope share token-bucket
     * state.
     */
    public function withRetryScope(string $scope): static
    {
        return $this->copyWith(['retryScope' => $scope]);
    }

    /**
     * Create a config with the endpoint resolver replaced.
     */
    public function withEndpointResolver(
        EndpointResolverInterface $resolver
    ): static
    {
        return $this->copyWith(['endpointResolver' => $resolver]);
    }

    /**
     * Create a config with the protocol replaced.
     */
    public function withProtocol(ProtocolInterface $protocol): static
    {
        $config = $this->copyWith([
            'protocol' => $protocol,
            'protocolId' => $protocol->getId(),
            'protocolIsExplicit' => true
        ]);

        return $config->service === null
            ? $config
            : $config->resolveProtocol();
    }

    /**
     * Create a config that selects a registered protocol by shape ID.
     */
    public function withProtocolId(string $protocolId): static
    {
        if ($protocolId === '') {
            throw new \InvalidArgumentException(
                'Protocol ID must be a non-empty shape ID.'
            );
        }

        $config = $this->copyWith([
            'protocol' => null,
            'protocolId' => $protocolId,
            'protocolIsExplicit' => false
        ]);

        return $config->service === null
            ? $config
            : $config->resolveProtocol();
    }

    /**
     * Create a config with the protocol resolver replaced.
     */
    public function withProtocolResolver(
        ProtocolResolverInterface $resolver
    ): static
    {
        $config = $this->copyWith([
            'protocol' => $this->hasExplicitProtocol()
                ? $this->protocol
                : null,
            'protocolResolver' => $resolver
        ]);

        return $config->service === null
            ? $config
            : $config->resolveProtocol();
    }

    /**
     * Return the selected protocol.
     */
    public function getProtocol(): ProtocolInterface
    {
        return $this->protocol ?? throw new \LogicException(
            'Client protocol has not been resolved.'
        );
    }

    /**
     * Return registered protocols modeled by the configured service.
     *
     * @return array<string, ProtocolInterface>
     */
    public function getApplicableProtocols(): array
    {
        if ($this->service === null) {
            throw new \LogicException(
                'Client service metadata must be configured before listing '
                . 'applicable protocols.'
            );
        }

        return $this->protocolResolver->getApplicableProtocols(
            $this->service
        );
    }

    /**
     * Return every registered protocol implementation.
     *
     * @return array<string, ProtocolInterface>
     */
    public function getRegisteredProtocols(): array
    {
        return $this->protocolResolver->getProtocols();
    }

    /**
     * Create a config with the transport replaced.
     */
    public function withTransport(TransportInterface $transport): static
    {
        return $this->copyWith(['transport' => $transport]);
    }

    /**
     * Create a config with the auth-scheme resolver replaced.
     */
    public function withAuthSchemeResolver(
        AuthSchemeResolverInterface $resolver
    ): static
    {
        return $this->copyWith(['authSchemeResolver' => $resolver]);
    }

    /**
     * Create a config with the identity resolver repository replaced.
     */
    public function withIdentityResolvers(
        IdentityResolversInterface $resolvers
    ): static
    {
        return $this->copyWith(['identityResolvers' => $resolvers]);
    }

    /**
     * Create a config with one identity resolver registered by identity type.
     *
     * Adds to the current {@see IdentityResolvers}, replacing any resolver
     * already registered for the same identity type.
     *
     * @param class-string<\Smithy\Client\Auth\IdentityInterface> $identityClass
     */
    public function withIdentityResolver(
        string $identityClass,
        IdentityResolverInterface $resolver
    ): static
    {
        $resolvers = $this->identityResolvers;
        if (!$resolvers instanceof IdentityResolvers) {
            throw new \LogicException(
                'withIdentityResolver() requires the default '
                . IdentityResolvers::class
                . ' repository; use withIdentityResolvers() instead.'
            );
        }

        return $this->copyWith([
            'identityResolvers' => $resolvers->with(
                $identityClass,
                $resolver
            )
        ]);
    }

    /**
     * Return the configured identity resolver repository.
     */
    public function getIdentityResolvers(): IdentityResolversInterface
    {
        return $this->identityResolvers;
    }

    /**
     * Create a config with the auth scheme preference replaced.
     *
     * @param list<string> $preference
     */
    public function withAuthSchemePreference(array $preference): static
    {
        return $this->copyWith([
            'authSchemePreference' => \array_values($preference)
        ]);
    }

    /**
     * Create a config with `$scheme` appended to the supported list.
     *
     * `NoAuthScheme` is always present. This method appends additional
     * schemes callers explicitly opt into.
     */
    public function withAddedAuthScheme(AuthSchemeInterface $scheme): static
    {
        return $this->withSupportedAuthSchemes([
            ...$this->supportedAuthSchemes,
            $scheme
        ]);
    }

    /**
     * Create a config with the supported auth schemes list replaced.
     *
     * `NoAuthScheme` is auto-re-added by the constructor if omitted.
     *
     * @param list<AuthSchemeInterface> $schemes
     */
    public function withSupportedAuthSchemes(array $schemes): static
    {
        return $this->copyWith([
            'supportedAuthSchemes' => \array_values($schemes)
        ]);
    }

    /**
     * Return the configured implementation of an auth scheme.
     */
    public function getSupportedAuthScheme(
        string $schemeId
    ): ?AuthSchemeInterface
    {
        return $this->supportedAuthSchemesById[$schemeId] ?? null;
    }

    /**
     * Create a config with a value stored in the `context` bag.
     */
    public function withContext(string $key, mixed $value): static
    {
        return $this->copyWith([
            'context' => [...$this->context, $key => $value]
        ]);
    }

    /**
     * Shallow copy the config, overriding fields named in `$overrides`.
     * Subclasses override this to include their own fields.
     *
     * Explicit `null` values are ignored and preserve the current value.
     *
     * @param array<string, mixed> $overrides
     */
    protected function copyWith(array $overrides): static
    {
        $protocol = \array_key_exists('protocol', $overrides)
            ? $overrides['protocol']
            : $this->protocol;
        $protocolResolver = $overrides['protocolResolver']
            ?? $this->protocolResolver;
        $protocolId = \array_key_exists('protocolId', $overrides)
            ? $overrides['protocolId']
            : $this->protocolId;
        $explicitFields = $this->explicitFieldsFor($overrides);
        $transport = $overrides['transport'] ?? $this->transport;
        $endpointResolver =
            $overrides['endpointResolver'] ?? $this->endpointResolver;
        $interceptors =
            $overrides['interceptors'] ?? $this->interceptorChain;
        $supportedAuthSchemes = $overrides['supportedAuthSchemes']
            ?? $this->supportedAuthSchemes;
        $authSchemeResolver = $overrides['authSchemeResolver']
            ?? $this->authSchemeResolver;
        $identityResolvers = $overrides['identityResolvers']
            ?? $this->identityResolvers;
        $retryStrategy =
            $overrides['retryStrategy'] ?? $this->retryStrategy;
        $retryScope = $overrides['retryScope'] ?? $this->retryScope;
        $context = $overrides['context'] ?? $this->context;
        $service = $overrides['service'] ?? $this->service;
        $pluginState = $overrides['pluginState'] ?? $this->pluginState;

        return new static(
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
            authSchemePreference: $overrides['authSchemePreference']
                ?? $this->authSchemePreference,
            service: $service,
            pluginState: $pluginState,
            explicitFields: $explicitFields,
            identityResolvers: $identityResolvers
        );
    }

    /**
     * Whether `$field` was set by the caller rather than filled by a default.
     *
     * A field is explicit when a non-null value was passed to the
     * constructor or set through its own `withXxx()` method.
     */
    public function isExplicit(ExplicitClientConfigField $field): bool
    {
        return $this->explicitFields[$field->value];
    }

    /**
     * Provenance map for a copy built from `$overrides`.
     *
     * Subclasses that override {@see copyWith()} pass the result as
     * `explicitFields` so defaulted values stay defaulted in the copy.
     *
     * @internal Used by configuration subclasses when copying base state.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, bool>
     */
    protected function explicitFieldsFor(array $overrides): array
    {
        $fields = $this->explicitFields;
        $protocol = ExplicitClientConfigField::Protocol->value;
        $fields[$protocol] = $overrides['protocolIsExplicit']
            ?? $fields[$protocol];
        foreach (ExplicitClientConfigField::cases() as $field) {
            if ($field->value !== $protocol && isset($overrides[$field->value])) {
                $fields[$field->value] = true;
            }
        }

        return $fields;
    }

    /**
     * Whether the current protocol was supplied explicitly.
     *
     * @internal Used by configuration subclasses when copying base state.
     */
    protected function hasExplicitProtocol(): bool
    {
        return $this->explicitFields[ExplicitClientConfigField::Protocol->value];
    }

    private function resolveWithPluginPredicate(
        \Closure $predicate
    ): static {
        $state = $this->pluginState->withPredicate($predicate);

        return $this->withResolvedPluginState($state);
    }

    private static function toClosure(callable $callable): \Closure
    {
        return $callable instanceof \Closure
            ? $callable
            : \Closure::fromCallable($callable);
    }

    /**
     * @param list<string> $preference
     *
     * @return list<string>
     */
    private static function validateAuthSchemePreference(
        array $preference
    ): array
    {
        $validated = [];
        foreach ($preference as $name) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    'Auth scheme preference names must be non-empty strings.'
                );
            }
            if (!\in_array($name, $validated, true)) {
                $validated[] = $name;
            }
        }

        return $validated;
    }
}
