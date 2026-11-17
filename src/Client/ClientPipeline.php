<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Async\Async;
use Smithy\Client\Auth\{
    IdentityInterface,
    IdentityNotFoundException,
    IdentityResolverInterface,
    ResolvedAuthScheme
};
use Smithy\Client\Interceptor\InputContext;
use Smithy\Client\Interceptor\InterceptorInterface;
use Smithy\Client\Interceptor\OutputContext;
use Smithy\Client\Interceptor\RequestContext;
use Smithy\Client\Interceptor\ResponseContext;
use Smithy\Client\Retry\RetryRequestFlagResolverInterface;
use Smithy\Client\Retry\TokenAcquisitionFailedException;
use Smithy\Core\Transport\{
    TransportContext,
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Run operations through serialization, retries, transport, and
 * deserialization.
 *
 * Invoke registered interceptors at each execution stage.
 *
 * An {@see AbstractClient} constructs one pipeline and reuses it across
 * operations.
 *
 * Hook error semantics (matching smithy-java's `ClientInterceptor`
 * documentation):
 *
 *  - Batch read hooks invoke every interceptor even if one throws. The most
 *    recent error replaces earlier errors.
 *  - All other read hooks fail fast: the first thrown exception aborts
 *    the batch and jumps to the enclosing completion hook.
 *  - Pre-attempt modify hooks (`modifyBeforeSerialization`,
 *    `modifyBeforeRetryLoop`, `modifyBeforeSigning`, `modifyBeforeTransmit`,
 *    `modifyBeforeDeserialization`) fail fast.
 *  - Completion modify hooks (`modifyBeforeAttemptCompletion`,
 *    `modifyBeforeCompletion`) pass the current error to each interceptor.
 *    A thrown exception stops the chain.
 *
 * Retry semantics: on an attempt-level failure the pipeline calls
 * `modifyBeforeAttemptCompletion` then `readAfterAttempt`, hands the
 * error to the {@see \Smithy\Client\Retry\RetryStrategyInterface}, waits
 * for the requested delay, and either retries or completes the execution.
 */
final class ClientPipeline
{
    /** @var array<string, ResolvedAuthScheme> Auth schemes by operation identity */
    private array $authSchemeByOperation = [];

    public function __construct(
        private readonly ClientConfig $config
    ) {}

    /**
     * Run the operation and return its output.
     */
    public function execute(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ?RequestOverrideConfig $overrides = null
    ): SerializableStructInterface
    {
        $config = $this->config;
        $interceptor = $config->interceptorChain->forOperation($operation);

        if ($interceptor->isEmpty()) {
            $transportContext = new TransportContext(
                $operation,
                $overrides?->resolveTransportOptions($config->transport)
            );

            return $this->executeWithoutInterceptors(
                $operation,
                $input,
                $config,
                $overrides,
                $transportContext
            );
        }

        $error = null;
        $reqCtx = null;
        $outCtx = null;

        try {
            $inputCtx = new InputContext($operation, $input, $config);
            $interceptor->readBeforeExecution($inputCtx);
            $transportContext = new TransportContext(
                $operation,
                $overrides?->resolveTransportOptions($config->transport)
            );

            $input = $interceptor->modifyBeforeSerialization($inputCtx);
            if ($input !== $inputCtx->input) {
                $inputCtx->replaceInput($input);
            }
            $interceptor->readBeforeSerialization($inputCtx);

            $request = $this->serialize($operation, $input);
            $request = $overrides?->applyToRequest($request) ?? $request;

            $reqCtx = new RequestContext(
                $operation,
                $input,
                $config,
                $request
            );

            $interceptor->readAfterSerialization($reqCtx);

            $request = $interceptor->modifyBeforeRetryLoop($reqCtx);
            if ($request !== $reqCtx->request) {
                $reqCtx->replaceRequest($request);
            }
            $retryRequest = $reqCtx->request;

            $requestFlags = self::resolveRetryRequestFlags(
                $operation,
                $config
            );
            $retryState = $config->retryStrategy->begin(
                $config->retryScope,
                $requestFlags
            );
            $strategyMaxAttempts = $config->retryStrategy->maxAttempts();

            $attempt = 1;
            while (true) {
                $reqCtx->replaceRequest($retryRequest);
                $attemptError = $this->executeAttempt(
                    $interceptor,
                    $reqCtx,
                    $overrides,
                    $transportContext,
                    $outCtx
                );

                $error = $this->completeAttempt(
                    $interceptor,
                    $outCtx,
                    $attemptError
                );

                if ($error !== null) {
                    if ($overrides?->maxAttempts !== null
                        && $attempt >= $overrides->maxAttempts
                    ) {
                        break;
                    }

                    // Confirm the request can be replayed before spending
                    // retry tokens; otherwise a non-replayable body would
                    // permanently deplete the bucket for a retry that never
                    // happens.
                    if (!$config->transport->prepareRequestForRetry(
                        $outCtx->request ?? $retryRequest,
                        $outCtx->response,
                        $transportContext,
                        $error
                    )) {
                        break;
                    }

                    try {
                        $delay = $config->retryStrategy->refresh(
                            $retryState,
                            $error
                        );
                    } catch (TokenAcquisitionFailedException $e) {
                        Async::delay($e->delay);
                        break;
                    }
                    if ($attempt >= $strategyMaxAttempts) {
                        break;
                    }

                    Async::delay($delay);
                    ++$attempt;

                    continue;
                }

                $config->retryStrategy->recordSuccess($retryState);
                break;
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        $outCtx ??= new OutputContext(
            $operation,
            $input,
            $config,
            $reqCtx?->request,
            null,
            null
        );

        return $this->completeExecution($interceptor, $outCtx, $error);
    }

    /**
     * Run attempt completion hooks and return the resulting error.
     */
    private function completeAttempt(
        InterceptorInterface $interceptor,
        OutputContext $ctx,
        ?\Throwable $error
    ): ?\Throwable
    {
        try {
            $output = $interceptor
                ->modifyBeforeAttemptCompletion($ctx, $error);
            $ctx->replaceOutput($output);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
            $ctx->replaceOutput(null);
        }

        try {
            $interceptor->readAfterAttempt($ctx, $error);
        } catch (\Throwable $e) {
            $error = $e;
        }

        return $error;
    }

    /**
     * Run execution completion hooks and return the final output.
     */
    private function completeExecution(
        InterceptorInterface $interceptor,
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface
    {
        $output = $ctx->output;
        try {
            $output = $interceptor->modifyBeforeCompletion($ctx, $error);
            $ctx->replaceOutput($output);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
            $ctx->replaceOutput(null);
        }

        try {
            $interceptor->readAfterExecution($ctx, $error);
        } catch (\Throwable $e) {
            $error = $e;
        }

        if ($error !== null) {
            throw $error;
        }
        if ($output === null) {
            throw new \LogicException(
                'Execution completed without an output.'
            );
        }

        return $output;
    }

    /**
     * Serialize the operation input.
     */
    private function serialize(
        OperationInterface $operation,
        SerializableStructInterface $input
    ): TransportRequestInterface
    {
        return $this->config->getProtocol()->serializeRequest(
            $operation,
            $input
        );
    }

    /**
     * Resolve attempt-scoped auth and endpoint state.
     *
     * Return the request with the endpoint applied. Write the signing auth
     * scheme, its identity resolver, and the resolved identity to the
     * reference parameters.
     */
    private function prepareAttemptRequest(
        OperationInterface $operation,
        SerializableStructInterface $input,
        TransportRequestInterface $request,
        ClientConfig $config,
        ?RequestOverrideConfig $overrides,
        ?ResolvedAuthScheme &$authScheme,
        ?IdentityResolverInterface &$identityResolver,
        ?IdentityInterface &$identity
    ): TransportRequestInterface
    {
        $preference = $overrides?->authSchemePreference;
        if ($preference === null && $config === $this->config) {
            // Resolution depends only on the operation and immutable config.
            $preference = $config->authSchemePreference;
            $cacheKey = $operation->getRuntimeIdentity();
            $resolvedAuthScheme = $this->authSchemeByOperation[$cacheKey]
                ??= $config->authSchemeResolver->resolveAuthScheme(
                    $operation,
                    $config,
                    $preference
                );
        } else {
            $preference ??= $config->authSchemePreference;
            $resolvedAuthScheme = $config
                ->authSchemeResolver
                ->resolveAuthScheme($operation, $config, $preference);
        }

        $identityResolver = $resolvedAuthScheme->identityResolver
            ?? $resolvedAuthScheme
                ->scheme
                ->getIdentityResolver($config->getIdentityResolvers());
        if ($identityResolver === null) {
            throw new IdentityNotFoundException(
                'Auth scheme '
                . $resolvedAuthScheme->scheme->getSchemeId()
                . ' has no usable identity resolver.'
            );
        }
        $identity = $identityResolver
            ->resolveIdentity($resolvedAuthScheme->identityProperties)
            ->unwrap();
        $endpoint = $config->endpointResolver->resolveEndpoint(
            $operation,
            $input,
            $config
        );
        $authScheme = $config
            ->authSchemeResolver
            ->resolveEndpointAuthScheme(
                $resolvedAuthScheme,
                $operation,
                $config,
                $endpoint->authSchemes,
                $preference
            );
        if ($authScheme->scheme !== $resolvedAuthScheme->scheme) {
            // A failure below must not leave the modeled identity in place.
            $identity = null;
            $identityResolver = $authScheme->identityResolver
                ?? $authScheme
                    ->scheme
                    ->getIdentityResolver($config->getIdentityResolvers());
            if ($identityResolver === null) {
                throw new IdentityNotFoundException(
                    'Auth scheme '
                    . $authScheme->scheme->getSchemeId()
                    . ' has no usable identity resolver.'
                );
            }
            $identity = $identityResolver
                ->resolveIdentity($authScheme->identityProperties)
                ->unwrap();
        }

        $request = $config->getProtocol()->setServiceEndpoint(
            $request,
            $endpoint
        );

        return $overrides?->applyToRequest($request) ?? $request;
    }

    /**
     * Execute one signed HTTP attempt and return its error.
     *
     * Write the attempt's output context to `$outCtx`. On success it contains
     * the deserialized output. On failure it describes how far the attempt
     * got.
     */
    private function executeAttempt(
        InterceptorInterface $interceptor,
        RequestContext $reqCtx,
        ?RequestOverrideConfig $overrides,
        TransportContext $transportContext,
        ?OutputContext &$outCtx
    ): ?\Throwable
    {
        $outCtx = null;
        $response = null;
        $output = null;
        $error = null;
        $request = $reqCtx->request;
        $authScheme = null;
        $identity = null;
        $identityResolver = null;

        try {
            $interceptor->readBeforeAttempt($reqCtx);
        } catch (\Throwable $e) {
            $outCtx = new OutputContext(
                $reqCtx->operation,
                $reqCtx->input,
                $reqCtx->config,
                $request,
                null,
                null
            );

            return $e;
        }

        try {
            $request = $this->prepareAttemptRequest(
                $reqCtx->operation,
                $reqCtx->input,
                $request,
                $reqCtx->config,
                $overrides,
                $authScheme,
                $identityResolver,
                $identity
            );
            $reqCtx->replaceRequest($request);

            $request = $interceptor->modifyBeforeSigning($reqCtx);
            if ($request !== $reqCtx->request) {
                $reqCtx->replaceRequest($request);
            }

            $interceptor->readBeforeSigning($reqCtx);

            $request = $authScheme->scheme->getSigner()->sign(
                $request,
                $identity,
                $authScheme->signerProperties
            )->signedRequest;
            $reqCtx->replaceRequest($request);

            $interceptor->readAfterSigning($reqCtx);

            $request = $interceptor->modifyBeforeTransmit($reqCtx);
            if ($request !== $reqCtx->request) {
                $reqCtx->replaceRequest($request);
            }

            $interceptor->readBeforeTransmit($reqCtx);

            $response = $this->config->transport->send(
                $request,
                $transportContext
            );

            $respCtx = new ResponseContext(
                $reqCtx->operation,
                $reqCtx->input,
                $reqCtx->config,
                $request,
                $response
            );

            $interceptor->readAfterTransmit($respCtx);

            $response = $interceptor->modifyBeforeDeserialization($respCtx);
            if ($response !== $respCtx->response) {
                $respCtx->replaceResponse($response);
            }

            $interceptor->readBeforeDeserialization($respCtx);

            $deserError = null;
            try {
                $output = $this->deserializeResponse(
                    $reqCtx->operation,
                    $response
                );
            } catch (\Throwable $e) {
                $deserError = $e;
            }

            $outCtx = new OutputContext(
                $reqCtx->operation,
                $reqCtx->input,
                $reqCtx->config,
                $request,
                $response,
                $output,
                $identity,
                $identityResolver
            );

            // This hook runs even when deserialization fails.
            try {
                $interceptor->readAfterDeserialization($outCtx, $deserError);
            } catch (\Throwable $e) {
                $deserError = $e;
            }

            if ($deserError !== null) {
                $error = $deserError;
                $output = null;
                $outCtx->replaceOutput(null);
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        $outCtx ??= new OutputContext(
            $reqCtx->operation,
            $reqCtx->input,
            $reqCtx->config,
            $request,
            $response,
            $output,
            $identity,
            $identityResolver
        );

        return $error;
    }

    private function executeWithoutInterceptors(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ClientConfig $config,
        ?RequestOverrideConfig $overrides,
        TransportContext $transportContext
    ): SerializableStructInterface
    {
        $request = $this->serialize($operation, $input);

        $requestFlags = self::resolveRetryRequestFlags($operation, $config);
        $retryState = $config->retryStrategy->begin(
            $config->retryScope,
            $requestFlags
        );
        $strategyMaxAttempts = $config->retryStrategy->maxAttempts();
        $authScheme = null;
        $identityResolver = null;
        $identity = null;

        $attempt = 1;
        while (true) {
            $attemptRequest = $request;
            $response = null;
            try {
                $attemptRequest = $this->prepareAttemptRequest(
                    $operation,
                    $input,
                    $request,
                    $config,
                    $overrides,
                    $authScheme,
                    $identityResolver,
                    $identity
                );
                $attemptRequest = $authScheme->scheme->getSigner()->sign(
                    $attemptRequest,
                    $identity,
                    $authScheme->signerProperties
                )->signedRequest;
                $response = $config->transport->send(
                    $attemptRequest,
                    $transportContext
                );
                $output = $this->deserializeResponse(
                    $operation,
                    $response
                );
            } catch (\Throwable $error) {
                if ($overrides?->maxAttempts !== null
                    && $attempt >= $overrides->maxAttempts
                ) {
                    throw $error;
                }
                // Confirm the request can be replayed before spending
                // retry tokens; otherwise a non-replayable body would
                // permanently deplete the bucket for a retry that never
                // happens.
                if (!$config->transport->prepareRequestForRetry(
                    $attemptRequest,
                    $response,
                    $transportContext,
                    $error
                )) {
                    throw $error;
                }
                try {
                    $delay = $config->retryStrategy->refresh(
                        $retryState,
                        $error
                    );
                    if ($attempt >= $strategyMaxAttempts) {
                        throw $error;
                    }
                    Async::delay($delay);
                    ++$attempt;
                } catch (TokenAcquisitionFailedException $e) {
                    Async::delay($e->delay);
                    throw $error;
                }

                continue;
            }

            $config->retryStrategy->recordSuccess($retryState);

            return $output;
        }
    }

    private function deserializeResponse(
        OperationInterface $operation,
        TransportResponseInterface $response
    ): SerializableStructInterface
    {
        return $this->config->getProtocol()->deserializeResponse(
            $operation,
            $response
        );
    }

    /**
     * Return retry request flags for this execution.
     */
    private static function resolveRetryRequestFlags(
        OperationInterface $operation,
        ClientConfig $config
    ): int
    {
        $resolver = $config->context[
            RetryRequestFlagResolverInterface::CONFIG_KEY
        ] ?? null;
        if (!$resolver instanceof RetryRequestFlagResolverInterface) {
            return 0;
        }

        return $resolver->resolve($operation, $config);
    }
}
