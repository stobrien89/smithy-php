<?php

declare(strict_types=1);

namespace Smithy\Json;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Store precomputed JSON metadata for a schema.
 *
 * Computed once on first access via schema extensions and cached for the
 * lifetime of the schema.
 */
final class JsonSchemaExtension
{
    /**
     * Create the cached JSON extension payload for a schema.
     */
    public function __construct(
        /**
         * Wire-name to member-index lookup used by the deserializer to
         * translate JSON object keys into positional slots in the
         * `$fields` array. Honors `@jsonName` when present; otherwise the
         * Smithy member name is used.
         *
         * @var array<string, int>
         */
        public readonly array $memberIndexByWireName,

        /**
         * Member-index to wire-name lookup used by the serializer to emit
         * JSON object keys from positional `$idx` parameters. Inverse of
         * `$memberIndexByWireName`.
         *
         * @var array<int, string>
         */
        public readonly array $wireNameByMemberIndex,

        /**
         * Raw variant of {@see $memberIndexByWireName} that ignores
         * `@jsonName` and keys strictly by the Smithy member name. Used
         * by the awsJson1.x protocols (which MUST NOT honor `@jsonName`);
         * the codec picks between this and the jsonName-honored map at
         * struct-open time.
         *
         * @var array<string, int>
         */
        public readonly array $rawMemberIndexByWireName,

        /**
         * Raw variant of {@see $wireNameByMemberIndex} that always emits
         * the Smithy member name (no `@jsonName`). Same pick-at-struct-
         * open semantics on the serialize side.
         *
         * @var array<int, string>
         */
        public readonly array $rawWireNameByMemberIndex,

        /**
         * Wire-name to packed member decode metadata used by the
         * deserializer's struct hot loop. Each entry is `[memberIndex,
         * shapeType]` for simple scalar/document members, `[memberIndex,
         * 12, timestampFormat]` for timestamps, `[memberIndex, 17|18,
         * resolvedTargetClass]` for enum/intEnum, or `[memberIndex,
         * 13|14|15|16, memberSchema]` for complex members that still route
         * to the existing list/map/struct/union helpers.
         *
         * @var array<string, array{0: int, 1: int, 2?: mixed}>
         */
        public readonly array $memberDecodeByWireName,

        /**
         * Raw-key variant of {@see $memberDecodeByWireName}. Same pick-at-
         * struct-open semantics as the raw wire-name maps.
         *
         * @var array<string, array{0: int, 1: int, 2?: mixed}>
         */
        public readonly array $rawMemberDecodeByWireName,

        /**
         * Parallel declaration-ordered list of `[wireName, decodeEntry]`
         * for the deserializer's speculative in-order lookup fast path.
         *
         * Borrowed from smithy-java's `SmithyMemberLookup` speculative
         * `expectedNext` hint: when JSON object keys arrive in schema
         * declaration order — the common case for AWS responses, since
         * codegen emits members in declaration order and servers usually
         * emit fields that way — the deserializer can match the next
         * member with a single string compare against `$orderedMembers[$hint][0]`
         * and advance the hint. On miss, `$memberDecodeByWireName` is the
         * O(1) fallback.
         *
         * PHP arrays preserve insertion order, so this list is populated
         * from the same `getMembers()` walk that fills the two hash maps
         * above — the ordering is authoritative.
         *
         * @var list<array{0: string, 1: array{0: int, 1: int, 2?: mixed}}>
         */
        public readonly array $orderedMembers,

        /**
         * Raw-key variant of {@see $orderedMembers}. Same declaration
         * order, memberName keys.
         *
         * @var list<array{0: string, 1: array{0: int, 1: int, 2?: mixed}}>
         */
        public readonly array $rawOrderedMembers,

        /**
         * Cached count of {@see $orderedMembers}, hoisted out of the
         * deserializer's per-key loop so the bounds check on the
         * speculative fast path is a single-op read.
         */
        public readonly int $orderedMemberCount,

        /**
         * Resolved timestamp format per timestamp member, keyed by
         * `memberIndex`. The provider folds `@timestampFormat` into this
         * map so the codec dispatches on a precomputed format instead of
         * reading the trait per write.
         *
         * Members that are not timestamps are absent from this map.
         *
         * @var array<int, TimestampFormat>
         */
        public readonly array $timestampFormats,

        /**
         * Whether this schema or any reachable descendant contains a
         * `BigInteger` or `BigDecimal` shape. Precomputed at extension
         * build time so `JsonSerializer::flush()` can skip the
         * `BigNumberToken` post-processing scan for the common case where
         * no bignum values exist in the payload.
         *
         * Recursive type references are treated conservatively: if the
         * provider hits a cycle mid-walk, this flag stays `false` for the
         * unresolved leg and the flush-time `str_contains` gate catches
         * any actual occurrences. Worst case is a single missed-prefix
         * scan; correctness is preserved.
         */
        public readonly bool $hasBigNumber,

        /**
         * Cached member count for structure / union schemas (0 for other shape
         * types). Populated by the provider so
         * {@see \Smithy\Json\JsonDeserializer::readNestedStruct} and
         * {@see \Smithy\Json\JsonDeserializer::readUnion} can size their
         * output arrays without paying a `Schema::getMemberCount()` call
         * on every nested read — that method is `??=`-cached on the
         * Schema, but avoiding the method dispatch entirely is measurable
         * for large lists of nested structs.
         */
        public readonly int $memberCount = 0,

        /**
         * Prebuilt `array_fill(0, memberCount, null)` template for struct /
         * union schemas, or `[]` when the schema has no members. PHP arrays
         * are copy-on-write, so the deserializer can assign this template
         * to its output slot and only pay the copy cost on the first member
         * write — same total allocation as `array_fill`, but with the fill
         * itself hoisted to provider time and shared across every read of
         * the same struct type in a document.
         *
         * Mirrors the XML codec's `XmlSchemaExtension::$membersTemplate`.
         *
         * @var array<int, null>
         */
        public readonly array $membersTemplate = [],

        /**
         * Pre-resolved list member {@see \Smithy\Core\Serde\ShapeType} as
         * its `int` value (mirroring {@see \Smithy\Core\Serde\Schema::$type})
         * for list-typed member schemas. Null for non-list schemas. Lets
         * {@see \Smithy\Json\JsonDeserializer::readList} dispatch on a
         * literal-int switch instead of the per-item `readMemberValue`
         * / `decodeItem` recursion.
         */
        public readonly ?int $listMemberType = null,

        /**
         * Pre-resolved {@see \Smithy\Core\Serde\TimestampFormat} for the
         * list's inner member when the inner shape is a timestamp; null
         * otherwise. Defaulted to
         * {@see \Smithy\Core\Serde\TimestampFormat::EpochSeconds} per the
         * restJson1 / awsJson1_x timestamp default. Lets the deserializer's
         * dedicated timestamp fast-loop skip the per-item
         * `TimestampFormat::fromTraitString(...) ??` chain.
         */
        public readonly ?TimestampFormat $listMemberTimestampFormat = null,

        /**
         * Pre-resolved target class FQCN for enum / intEnum list items.
         * Resolved by the provider at build time so
         * {@see \Smithy\Json\JsonDeserializer::readList} can hydrate enum
         * items without per-item `resolveTarget()` / `getExtension()`
         * work.
         *
         * @var class-string|null
         */
        public readonly ?string $listMemberResolvedTargetClass = null,

        /**
         * Pre-resolved map value {@see \\Smithy\\Core\\Serde\\ShapeType}
         * as its `int` value (mirroring
         * {@see \\Smithy\\Core\\Serde\\Schema::$type}) for map-typed
         * member schemas. Null for non-map schemas. Lets
         * {@see \\Smithy\\Json\\JsonDeserializer::readMap} dispatch on a
         * literal-int switch instead of the per-entry `decodeItem`
         * recursion.
         */
        public readonly ?int $mapValueType = null,

        /**
         * Pre-resolved {@see \\Smithy\\Core\\Serde\\TimestampFormat} for
         * the map's value member when the value shape is a timestamp;
         * null otherwise. Defaulted to
         * {@see \\Smithy\\Core\\Serde\\TimestampFormat::EpochSeconds} per
         * the restJson1 / awsJson1_x timestamp default.
         */
        public readonly ?TimestampFormat $mapValueTimestampFormat = null,

        /**
         * Whether this list / map member schema carries `@sparse`.
         * Populated by the provider so
         * {@see \Smithy\Json\JsonSerializer::serializeList},
         * `serializeMap`, `renderListInline`, and `renderMapInline` can
         * read a single `bool` instead of a per-call
         * `Schema::hasTrait()` lookup — trait lookups have no place on
         * the serde hot path.
         */
        public readonly bool $sparse = false,

        /**
         * Pre-resolved {@see TimestampFormat} for a timestamp-typed
         * member schema, defaulted to
         * {@see TimestampFormat::EpochSeconds} per the JSON default when
         * no `@timestampFormat` is present. Null for non-timestamp
         * schemas. Used by
         * {@see \Smithy\Json\JsonSerializer::renderTimestamp}, which
         * receives just the member schema and no parent-frame info, so it
         * can't fall back to {@see $timestampFormats} keyed by
         * `memberIndex`.
         */
        public readonly ?TimestampFormat $memberTimestampFormat = null,

        /**
         * Pre-resolved target class FQCN for enum / intEnum / struct /
         * union member schemas. Resolved by the provider at build time
         * via `$schema->targetClass ?? $schema->resolveTarget()?->targetClass`
         * so the deserializer's enum-hydrate + union-resolve paths can
         * read a single class-string pointer instead of walking
         * {@see \Smithy\Core\Serde\Schema::resolveTarget()} on every
         * list item / union frame. Null when the member has no
         * generated target class (synthetic runtime schemas).
         *
         * @var class-string|null
         */
        public readonly ?string $resolvedTargetClass = null,

        /**
         * Pre-resolved target class FQCN for enum / intEnum map values.
         * Resolved by the provider at build time so
         * {@see \\Smithy\\Json\\JsonDeserializer::readMap} can hydrate enum
         * entries without per-entry `resolveTarget()` / `getExtension()`
         * work.
         *
         * @var class-string|null
         */
        public readonly ?string $mapValueResolvedTargetClass = null,

        /**
         * Pre-resolved target Schema for union-typed member schemas.
         * `readUnion` needs the full schema (to look up variant members
         * by wire name), not just the class string. Resolved once via
         * `$schema->resolveTarget()` when `$schema->getMemberCount() === 0`,
         * else `$schema` itself. Null for non-union / non-resolvable
         * member schemas.
         */
        public readonly ?Schema $resolvedTargetSchema = null,

        /**
         * Wire-name to packed union-variant decode metadata used by
         * {@see \Smithy\Json\JsonDeserializer::readUnion}. Each entry is
         * `[memberName, shapeType]` for simple scalar/document variants,
         * `[memberName, 12, timestampFormat]` for timestamps,
         * `[memberName, 17|18, resolvedTargetClass]` for enum/intEnum,
         * or `[memberName, 13|14|15|16, memberSchema]` for complex
         * variants.
         *
         * @var array<string, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $unionDecodeByWireName = [],

        /**
         * Raw-key variant of {@see $unionDecodeByWireName}. Same
         * pick-at-union-open semantics as the raw wire-name maps.
         *
         * @var array<string, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $rawUnionDecodeByWireName = [],

        /**
         * Member-name to packed union-variant encode metadata used by
         * {@see \Smithy\Json\JsonSerializer::renderUnion}. Each entry is
         * `[wireName, shapeType]` for simple scalar/document/bignum
         * variants, `[wireName, 12, timestampFormat]` for timestamps, or
         * `[wireName, 13|14|15|16, memberSchema]` for complex variants.
         *
         * @var array<string, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $unionEncodeByMemberName = [],

        /**
         * Raw-wire variant of {@see $unionEncodeByMemberName}. Same
         * pick-at-union-open semantics as the raw wire-name maps.
         *
         * @var array<string, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $rawUnionEncodeByMemberName = []
    ) {}
}
