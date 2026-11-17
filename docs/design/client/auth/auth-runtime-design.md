# Auth Runtime Design

**Status:** Current as of September 30, 2026

**Package:** `smithy-php` (`Smithy\Client\Auth\`, `Smithy\Client\`)

## Summary

Smithy-PHP resolves authentication per request attempt. Operation metadata
supplies modeled auth scheme IDs. Endpoint resolution can replace that
selection and add signer-property overrides. `ClientConfig` supplies the
runtime schemes the client can use and a repository of identity resolvers.

For each attempt, the pipeline selects a supported modeled scheme and resolves
its identity before endpoint resolution. It then resolves the endpoint,
reconciles any authoritative endpoint auth options, resolves another identity
when the scheme changes, and signs the endpoint-bound transport request. Core
Smithy runtime owns the contracts and neutral schemes. Service packages and
vendor packages own concrete identities, credential sources, and signers.

## Goals

1. Keep Smithy core independent of vendor credential rules.
2. Make auth scheme selection deterministic for every attempt.
3. Let endpoint rules override modeled auth when the ruleset requires it.
4. Support customer preference without changing generated operation code.
5. Reuse long-lived identity chains across requests.
6. Keep signing independent of the underlying transport message type.

## Architecture

```text
Smithy model
    |
    |  effective @auth traits
    v
Operation metadata
    |
    v
AuthSchemeResolver
    |
    +---------------------------+
                                |
EndpointResolver -> Endpoint ---+--> ClientPipeline
                                      |
ClientConfig -------------------------+
    |
    +-> supported auth schemes
    +-> auth scheme preference
    +-> identity resolvers

ClientPipeline
    |
    v
AuthScheme -> IdentityResolver -> Identity -> Signer -> signed request
```

The runtime never parses raw Smithy traits during a request. Generated and
dynamic operation metadata expose effective auth schemes through
`OperationInterface::getEffectiveAuthSchemes()`. The default
`AuthSchemeResolver` reads that normalized metadata.

## Runtime Types

| Type | Responsibility |
|---|---|
| `AuthSchemeInterface` | Bind a scheme ID to an identity resolver and signer. It receives the client context bag for property defaults and an `IdentityResolversInterface` for resolver lookup, never `ClientConfig`. |
| `AuthSchemeOption` | Carry one candidate ID plus independent identity and signer property overrides. |
| `AuthSchemeResolverInterface` | Select modeled auth, then reconcile endpoint auth for an attempt. |
| `ResolvedAuthScheme` | Carry the selected implementation and effective identity and signer properties. |
| `SignerInterface` | Sign one transport request and release attempt-scoped signing resources. |
| `SignerResult` | Carry the signed request and reusable signature metadata. |
| `IdentityInterface` | Carry signer-specific identity material. |
| `IdentityResolverInterface` | Resolve an identity from scheme properties. |
| `IdentityResolversInterface` | Look up an identity resolver by identity type; returns null when a scheme's identity type is unregistered. |
| `IdentityResult` | Distinguish a successful identity, an expected source miss, and a thrown fatal failure. |
| `IdentityChain` | Assemble ordered providers and try resolvers in order. |
| `ChainSetup` | Share assembly state while providers register resolvers. |
| `OrderingConstraint` | Place a provider at, before, or after a slot. |
| `ResolvedIdentityMetadata` | Record the provider that resolved identity. |
| `EndpointAuthScheme` | Carry endpoint-selected scheme ID and properties. |

The core runtime includes `smithy.api#noAuth` and
`smithy.api#httpBearerAuth`. Other schemes are registered by packages that
understand their identity material and signing rules.

## Client Configuration

`ClientConfig` owns auth runtime dependencies:

- `supportedAuthSchemes`: scheme implementations available to the pipeline
- `authSchemeResolver`: selects a supported scheme using operation and
  endpoint metadata
- `authSchemePreference`: customer-preferred short scheme names
- `identityResolvers`: an `IdentityResolversInterface` repository of identity
  resolvers keyed by identity type, passed to each scheme's
  `getIdentityResolver()`

`withIdentityResolver()` registers one resolver by identity type;
`withIdentityResolvers()` replaces the repository.

`NoAuthScheme` is always present. The constructor appends it when callers do
not include it in `supportedAuthSchemes`. That makes anonymous operations
work without extra client setup.

`withAddedAuthScheme()` appends a scheme to the supported list.
`withSupportedAuthSchemes()` replaces the list and still preserves
`NoAuthScheme` through the constructor invariant.

`withAuthSchemePreference()` stores short scheme names, not full shape IDs.
For `smithy.api#httpBearerAuth`, the preference name is `httpBearerAuth`.

## Scheme Selection

Selection has two attempt-scoped phases.

`AuthSchemeResolverInterface::resolveAuthScheme()` first selects from ordered
modeled `AuthSchemeOption` values. The default resolver derives one option per
effective operation auth trait. A vendor resolver can override
`resolveAuthSchemeOptions()` to add scheme-specific defaults or independent
identity and signer property overrides.

The resolver applies the request-level preference, or
`ClientConfig::$authSchemePreference`, without discarding duplicate options.
It selects the first option whose full scheme ID exists in
`ClientConfig::$supportedAuthSchemes`.

After endpoint resolution,
`resolveEndpointAuthScheme()` reconciles the modeled result with the
endpoint's ordered auth options:

- no endpoint options preserve the modeled selection
- a non-empty endpoint list is authoritative
- preferences reorder only the endpoint-provided candidates
- an endpoint option for the same scheme retains the modeled identity
  properties and overlays endpoint signer properties
- an endpoint option for another scheme applies that scheme's defaults and
  resolves a replacement identity

Endpoint properties are signer overrides. They are not passed to identity
resolution. A modeled scheme omitted by an authoritative endpoint list is not
added back.

If no modeled option is supported, the resolver throws a modeled-auth
mismatch. If an endpoint list is present but unsupported, it throws an
endpoint-specific auth error.

### Preference names

Preferences use the part of a shape ID after `#`. The runtime derives this by
stripping the namespace:

```text
smithy.api#httpBearerAuth -> httpBearerAuth
smithy.api#noAuth -> noAuth
```

This lets user-facing config avoid Smithy namespaces while the runtime still
matches exact scheme IDs.

## Identity Resolution

An `IdentityResolverInterface` returns an `IdentityResult` from
identity-specific properties. A scheme obtains its resolver from
`getIdentityResolver()`, either from its own configuration or by looking the
identity type up in the `IdentityResolversInterface` repository. A null return
means the scheme has no usable resolver; the pipeline then raises an
identity-not-found error for that scheme rather than signing. Scheme defaults
and option overrides are merged before the resolver is called. Signer
properties are kept separate.

`IdentityResult::success()` carries an identity. `IdentityResult::notFound()`
represents an expected source-specific miss, such as an unconfigured
environment provider, and records the resolver and diagnostic message.
Unexpected or unrecoverable failures are thrown. This lets chains continue
past expected misses without hiding fatal resolver failures.

Identity resolution occurs while preparing each attempt, before signing
hooks. If endpoint reconciliation changes the scheme, the pipeline resolves
the replacement scheme's identity. It then passes the selected identity and
signer properties to the signer after `modifyBeforeSigning` and
`readBeforeSigning`.

Selection, identity resolution, endpoint reconciliation, and signing all run
again on retries. Their failures enter attempt completion and the retry
strategy like other attempt failures.

## Identity Chains

`IdentityChain` provides a generic engine for ordered credential sources. It
does not name credential sources. The caller supplies:

- identity type
- provider list
- canonical slot order
- setup properties

Each provider declares an `OrderingConstraint`. A provider can own a standard
slot, run before a slot, or run after a slot. `IdentityChain` validates
duplicate provider names, unknown slots, and duplicate standard-slot claims.

Assembly runs once in the constructor:

1. Sort providers by slot order and constraint.
2. Create one `ChainSetup` with shared properties.
3. Call each provider's `setup()` method in sorted order.
4. Stop when a provider registers a terminal resolver.
5. Store resolver registrations and detected slots.

Providers can register terminal or non-terminal resolvers:

- Terminal resolvers stop assembly.
- Non-terminal resolvers allow later providers to register fallback entries.

This model lets a vendor layer encode its source precedence without adding
vendor branches to Smithy core.

### Resolution flow

`IdentityChain::resolveIdentity()` tries registered resolvers in order:

1. Return a cached identity when it is still fresh.
2. Return an expected miss if no resolver was registered.
3. Try each registered resolver until one returns a successful result.
4. Record expected misses and cache the first successful identity.
5. Record `ResolvedIdentityMetadata` for the winning provider.
6. Return one aggregate expected miss when every resolver misses.

Cache entries are scoped to the identity-property array. The refresh window is
300 seconds. An identity without an expiration time stays cached unless a
matching rejection marks it for refresh. An expiring identity is refreshed
when its expiration time enters the refresh window.

`invalidate()` forwards the rejected identity to every registered resolver. If
it matches the currently cached identity, by object identity unless a vendor
supplies another matcher, the chain marks that cache entry for refresh. It
does not evict an unrelated identity that replaced the rejected value.

## Caching Identity Resolver

`CachingIdentityResolver` wraps any resolver. It caches the most recent
successful identity until the identity enters its refresh window. The default
refresh window is 300 seconds.

This decorator is useful for standalone resolvers that do not need the
provider assembly behavior of `IdentityChain`. Cache entries are
property-scoped and expected misses are not cached. Rejected identities are
forwarded to the wrapped resolver, while only a matching cached identity is
marked for refresh.

## Signing

A `SignerInterface` transforms one transport request:

```text
TransportRequestInterface + IdentityInterface + properties -> SignerResult
```

`SignerResult` contains the signed request and an optional reusable signature.
Schemes that do not produce reusable signature metadata return an empty
signature. HTTP signers unwrap `HttpTransportRequest`, apply immutable PSR-7
request transformations, and return an envelope containing the replacement
request.

### No auth

`NoAuthScheme` uses `AnonymousIdentityResolver` and `NullSigner`.
`NullSigner` returns the request unchanged with an empty signature.

### HTTP bearer auth

`HttpBearerAuthScheme` uses a configured token provider and
`HttpBearerAuthSigner`. When no provider is configured it looks up a
`BearerToken` resolver from the `IdentityResolversInterface` repository.

`HttpBearerAuthSigner` requires:

- a `BearerToken` identity
- an HTTPS request URI

It signs by setting the `Authorization` header to `Bearer <token>`.

## Endpoint Auth

Endpoint rulesets may return `EndpointAuthScheme` values on the resolved
`Endpoint`. Each value carries a scheme ID and signer-property overrides.
When the endpoint returns any auth schemes, the resolver considers only that
authoritative list. Modeled schemes are used only when the endpoint returns
none.

Endpoint auth properties are signer hints. Smithy core treats them as opaque
data. The scheme implementation decides which keys it accepts.

Endpoint headers are applied before signing. That lets signers include
endpoint-provided headers in the signature when the scheme requires it.

## Failure Modes

Auth failures surface from three places:

- scheme selection, when the client does not support any candidate scheme
- identity resolution, when the selected resolver cannot produce identity
- signing, when the signer rejects the identity, request, or properties

Selection failures are deterministic configuration errors. Identity and
signing failures happen inside an attempt and are handled by the retry loop.

## Extension Points

Vendor and service packages extend auth by supplying:

1. `IdentityInterface` implementations for their identity material.
2. `IdentityResolverInterface` implementations for credential sources.
3. `AuthSchemeInterface` implementations that bind resolvers to signers.
4. `AuthSchemeResolverInterface` implementations for custom selection.
5. `ChainIdentityProviderInterface` implementations for ordered chains.

Core Smithy runtime does not inspect concrete identity fields. That boundary
keeps service-specific authentication out of the shared client pipeline.

## Testing and Validation

The Smithy auth suite covers:

- identity-chain ordering and terminal registration
- setup context, property scoping, and metadata propagation
- expected misses versus fatal resolver failures
- cached identity refresh and targeted invalidation
- forwarding invalidation through decorators and chain registrations
- auth option ordering and independent identity/signer overrides
- aggregate provider failures
- bearer token validation and HTTPS enforcement
- no-auth request handling
- signer results and cleanup behavior

The AWS auth suite covers SigV4 canonical requests, signing keys, session
tokens, payload hashes, credential-chain invalidation, and signer integration.
Endpoint suites exercise endpoint-selected authentication and signer-property
overrides.

## Open Follow-Ups

- Add telemetry wiring for `ResolvedIdentityMetadata::featureIds`.
- Add event-stream signing when the event-stream runtime is introduced.

## Related Documents

- [Client execution pipeline](../client-runtime-design.md)
- [Endpoint resolution](../endpoint/endpoint-resolution.md)
- [Endpoint testing and validation](../endpoint/testing-and-validation.md)
- [Pagination runtime](../pagination/pagination-runtime-design.md)
