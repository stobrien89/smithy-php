<?php

declare(strict_types=1);

namespace Smithy\Json;

use Smithy\Core\Serde\AbstractCodec;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Provide JSON serialization and deserialization for Smithy shapes.
 *
 * Reuses one {@see JsonSerializer} per codec instance and applies the
 * configured `@jsonName` policy to both serialization and deserialization.
 */
final class JsonCodec extends AbstractCodec
{
    /**
     * Lazily-instantiated pooled serializer. `null` until the first call
     * to {@see createSerializer}; retained and reset between calls
     * thereafter.
     */
    private ?ShapeSerializerInterface $serializer = null;

    /**
     * Create a JSON codec for the requested `@jsonName` policy.
     */
    public function __construct(
        /**
         * Whether the codec should honor `smithy.api#jsonName` when
         * resolving wire member names on the way out (serialize) and
         * back in (deserialize). `true` matches `restJson1` semantics;
         * `false` (used by the awsJson1.x family) directs the codec at
         * the raw `memberName`-keyed lookup arrays precomputed on
         * {@see JsonSchemaExtension}.
         *
         * Consulted once per struct-open by the serializer and
         * deserializer, so the emit / dispatch fast path stays
         * branchless.
         */
        private readonly bool $honorJsonName = true
    ) {}

    /**
     * Return a reusable JSON serializer instance.
     */
    public function createSerializer(): ShapeSerializerInterface
    {
        if ($this->serializer === null) {
            $this->serializer = new JsonSerializer($this->honorJsonName);
        } else {
            // Clear the previous operation's frame stack / accumulated
            // root so the same instance behaves like a fresh construct.
            $this->serializer->reset();
        }
        return $this->serializer;
    }

    /**
     * Create a JSON deserializer for the supplied wire body.
     */
    public function createDeserializer(string $source): ShapeDeserializerInterface
    {
        return new JsonDeserializer($source, $this->honorJsonName);
    }
}
