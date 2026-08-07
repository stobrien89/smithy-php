<?php

declare(strict_types=1);

namespace Smithy\Xml;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Store precomputed XML metadata for a schema.
 *
 * Computed once on first access via schema extensions and cached for the
 * lifetime of the schema.
 *
 * @internal Codec-specific metadata attached to XML schemas. External code
 *           should not rely on this field layout.
 */
final class XmlSchemaExtension
{
    /**
     * Component-type discriminants for {@see $memberComponentTypes}.
     *
     * Encoded as `int` so the serializer can dispatch with a literal-int
     * switch (matching the project-wide {@see Schema::$type} idiom. Opcache
     * emits a packed jump table).
     */
    public const COMPONENT_ELEMENT = 0;
    public const COMPONENT_ATTRIBUTE = 1;
    public const COMPONENT_ELEMENT_WITH_NAMESPACE = 2;

    /**
     * Create an XML schema extension.
     */
    public function __construct(
        // --- Struct info ---
        /** Resolved element name for this struct (xmlName ?? shape name) */
        public readonly string $xmlName = '',
        /** Resolved namespace ['uri' => ..., 'prefix' => ...] or null */
        public readonly ?array $xmlNamespace = null,
        /** @var array<int, string> memberIndex -> XML wire name */
        public readonly array $memberWireNames = [],
        /** attrXmlName -> Schema for pre-filtered xmlAttribute members */
        public readonly array $attributeMembers = [],
        /**
         * @var list<array{0: string, 1: int, 2: int, 3?: TimestampFormat}>
         */
        public readonly array $attributeDeserMembers = [],
        /** elementXmlName -> Schema for pre-filtered regular element members */
        public readonly array $elementMembers = [],
        /** flatName → ['member' => Schema, 'listMember' => Schema] */
        public readonly array $flattenedLists = [],
        /**
         * flatName -> ['member' => Schema, 'keyName' => string,
         * 'valueName' => string, 'mapValue' => Schema]
         */
        public readonly array $flattenedMaps = [],
        /**
         * @var array<string, array>
         *     xmlName -> [kind, memberIndex, memberSchema, ...payload]
         *     kind 0 => [0, memberIndex, memberSchema, shapeType,
         *     ?TimestampFormat]
         *     kind 2 => [2, memberIndex, memberSchema, listMemberSchema]
         *     kind 3 => [3, memberIndex, memberSchema, keyName,
         *     valueName, mapValueSchema]
         */
        public readonly array $childMembers = [],
        /**
         * @var list<array{0: string, 1: array}>
         */
        public readonly array $orderedChildMembers = [],
        /** Cached count of `orderedChildMembers`. */
        public readonly int $childMemberCount = 0,

        /**
         * @var array<int, int>
         */
        public readonly array $memberComponentTypes = [],
        /**
         * @var array<int, array{0: string, 1: string, 2: string}>
         */
        public readonly array $nsAttrs = [],
        /** True when the struct has one or more `@xmlAttribute` members. */
        public readonly bool $hasAttributes = false,
        /** True when any element member carries a per-member namespace. */
        public readonly bool $hasElementsWithNamespace = false,
        /**
         * @var array<int, TimestampFormat>
         *     memberIndex -> TimestampFormat enum for timestamp members
         */
        public readonly array $tsFormats = [],
        /** Cached member count for structure and union schemas. */
        public readonly int $memberCount = 0,
        /**
         * @var array<int, null>
         */
        public readonly array $membersTemplate = [],

        // --- Member info (for scalar write* methods) ---

        /** Whether this member should be written as an XML attribute */
        public readonly bool $isAttribute = false,
        /** Timestamp format for timestamp members, or null otherwise. */
        public readonly ?TimestampFormat $timestampFormat = null,
        /**
         * mediaType trait value for httpPayload content-type resolution.
         */
        public readonly ?string $mediaType = null,

        // --- List info ---
        /** Resolved child element name for list items */
        public readonly ?string $listMemberName = null,
        /** Whether this list is flattened */
        public readonly bool $flattened = false,
        /** List member `ShapeType` stored as its int value. */
        public readonly ?int $listMemberType = null,
        /** List member schema, or null when absent. */
        public readonly ?Schema $listMemberSchema = null,
        /**
         * @var array{uri: string, prefix?: string}|null
         */
        public readonly ?array $listMemberNamespace = null,
        /** Timestamp format for timestamp list members, or null otherwise. */
        public readonly ?TimestampFormat $listMemberTimestampFormat = null,
        /** Nested extension for list members that need recursive reads. */
        public readonly ?XmlSchemaExtension $listMemberExtension = null,


        // --- Union info ---
        /**
         * xmlName -> memberSchema for union variant lookup in
         * deserialization
         */
        public readonly array $unionMembersByXmlName = [],
        /**
         * memberName -> memberSchema for union variant lookup in
         * serialization
         */
        public readonly array $unionMembersByName = [],

        // --- Map info ---
        /** Resolved key element name */
        public readonly ?string $mapKeyName = null,
        /** Resolved value element name */
        public readonly ?string $mapValueName = null,
        /** Entry wrapper name ("entry" or flattened name) */
        public readonly ?string $mapEntryName = null,
        /**
         * Pre-resolved map value Schema. Saves a `getMapValue()` call on every
         * map-of-X deserialize iteration.
         */
        public readonly ?Schema $mapValueSchema = null,
        /** Timestamp format for timestamp map values, or null otherwise. */
        public readonly ?TimestampFormat $mapValueTimestampFormat = null,
        /** Nested extension for map values that need recursive reads. */
        public readonly ?XmlSchemaExtension $mapValueExtension = null,

        // --- Resolved target (enum / intEnum / struct / union member
        // schemas) ---
        /** @var class-string|null */
        public readonly ?string $resolvedTargetClass = null,
        /** Resolved target schema for recursive member references. */
        public readonly ?Schema $resolvedTargetSchema = null,
    ) {}
}
