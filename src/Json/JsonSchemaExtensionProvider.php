<?php

declare(strict_types=1);

namespace Smithy\Json;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SchemaExtensionKey;
use Smithy\Core\Serde\SchemaExtensionProviderInterface;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Provide precomputed JSON metadata for schemas.
 *
 * Computes {@see JsonSchemaExtension} lazily on first access per schema.
 */
final class JsonSchemaExtensionProvider
    implements SchemaExtensionProviderInterface
{
    /**
     * Singleton extension key promoted to a public static field so hot-path
     * callers (`renderUnion`, `readStruct`, etc.) can bind it to a local
     * `static` once per method-instance and skip the method-call machinery
     * on every subsequent call. `$KEY` is null until the first `key()`
     * invocation warms it — direct field readers should coalesce through
     * `key()` on the first miss.
     */
    public static ?SchemaExtensionKey $KEY = null;

    /**
     * Singleton extension key (lazy on first access). Kept as the canonical
     * accessor even though `$KEY` is public: it's the seam where the
     * `new SchemaExtensionKey(new self())` allocation lives, and reroutes
     * through the same static so `$KEY` stays warm after the first call.
     */
    public static function key(): SchemaExtensionKey
    {
        return self::$KEY ??= new SchemaExtensionKey(new self());
    }

    /**
     * Build JSON extension data for the supplied schema when needed.
     */
    public function provide(Schema $schema): ?JsonSchemaExtension
    {
        // List-typed member schemas get a lightweight extension carrying
        // the pre-resolved inner type + timestamp format + `@sparse` bit
        // so the serializer / deserializer hot paths can dispatch on
        // literal-int / bool without per-call trait lookups.
        if ($schema->type === ShapeType::List_->value) {
            return $this->buildListInfo($schema);
        }

        // Map-typed member schemas get the same treatment: the value
        // type / timestamp format / enum target class / `@sparse` bit
        // are folded in so serialize + deserialize hot paths can
        // dispatch on literal-int / bool / class-string without trait
        // lookups or target resolution.
        if ($schema->type === ShapeType::Map->value) {
            return $this->buildMapInfo($schema);
        }

        // Timestamp-typed member schemas get a lightweight extension
        // carrying the pre-resolved format so `renderTimestamp` doesn't
        // parse the `@timestampFormat` trait on every render. Default
        // is epoch-seconds per the Smithy JSON timestamp default.
        if ($schema->type === ShapeType::Timestamp->value) {
            return $this->buildTimestampInfo($schema);
        }

        // Enum / IntEnum member schemas: pre-resolve the target class
        // so {@see \Smithy\Json\JsonDeserializer} can call
        // `$info->resolvedTargetClass::fromValue(...)` without a
        // `resolveTarget()` walk per element of an enum-list.
        if (
            $schema->type === ShapeType::Enum->value
            || $schema->type === ShapeType::IntEnum->value
        ) {
            return $this->buildEnumMemberInfo($schema);
        }

        if (
            $schema->type !== ShapeType::Structure->value
            && $schema->type !== ShapeType::Union->value
        ) {
            return null;
        }

        // Two parallel wire-name lookup pairs. The `raw*` variants
        // always key by the Smithy member name; the default variants
        // honor `@jsonName`. Both populated in one walk so extension-
        // build cost stays a single traversal.
        $byWireName = [];
        $byIndex = [];
        $memberDecodeByWireName = [];
        $unionDecodeByWireName = [];
        $unionEncodeByMemberName = [];
        $orderedMembers = [];
        $rawByWireName = [];
        $rawByIndex = [];
        $rawMemberDecodeByWireName = [];
        $rawUnionDecodeByWireName = [];
        $rawUnionEncodeByMemberName = [];
        $rawOrderedMembers = [];
        $timestampFormats = [];

        foreach ($schema->getMembers() as $member) {
            $idx = $member->memberIndex;
            $memberName = $member->memberName;
            $wireName = $member->getTrait(Schema::T_JSON_NAME) ?? $memberName;

            $byWireName[$wireName] = $idx;
            $byIndex[$idx] = $wireName;

            // Raw pair — keyed by memberName regardless of `@jsonName`.
            // When no `@jsonName` is present the two pairs share
            // entries; the redundant assignment is cheaper than a
            // branch on the (rare) presence check.
            $rawByWireName[$memberName] = $idx;
            $rawByIndex[$idx] = $memberName;

            if ($member->type === 17 || $member->type === 18) {
                $targetClass = $member->targetClass;
                if ($targetClass === null && $member->getMemberCount() === 0) {
                    $targetClass = $member->resolveTarget()->targetClass;
                }
                $decodeEntry = [$idx, $member->type, $targetClass];
            } elseif ($member->type === 12) {
                $resolved = TimestampFormat::fromTraitString(
                    $member->getTrait(Schema::T_TIMESTAMP_FORMAT)
                );
                // restJson1 / awsJson1.x default timestamp format is
                // epoch-seconds.
                $resolved ??= TimestampFormat::EpochSeconds;
                $timestampFormats[$idx] = $resolved;
                $decodeEntry = [$idx, 12, $resolved];
            } elseif (
                $member->type === 13
                || $member->type === 14
                || $member->type === 15
                || $member->type === 16
            ) {
                $decodeEntry = [$idx, $member->type, $member];
            } else {
                $decodeEntry = [$idx, $member->type];
            }

            $memberDecodeByWireName[$wireName] = $decodeEntry;
            $unionDecodeByWireName[$wireName] =
                \count($decodeEntry) === 3
                    ? [$memberName, $decodeEntry[1], $decodeEntry[2]]
                    : [$memberName, $decodeEntry[1]];
            $unionEncodeByMemberName[$memberName] =
                \count($decodeEntry) === 3
                    ? [$wireName, $decodeEntry[1], $decodeEntry[2]]
                    : [$wireName, $decodeEntry[1]];
            // Parallel ordered list — the deserializer's speculative
            // in-order fast path matches against `[wireName, decodeEntry]`
            // here and advances a hint counter on hit.
            $orderedMembers[] = [$wireName, $decodeEntry];
            $rawMemberDecodeByWireName[$memberName] = $decodeEntry;
            $rawUnionDecodeByWireName[$memberName] =
                \count($decodeEntry) === 3
                    ? [$memberName, $decodeEntry[1], $decodeEntry[2]]
                    : [$memberName, $decodeEntry[1]];
            $rawUnionEncodeByMemberName[$memberName] =
                \count($decodeEntry) === 3
                    ? [$memberName, $decodeEntry[1], $decodeEntry[2]]
                    : [$memberName, $decodeEntry[1]];
            $rawOrderedMembers[] = [$memberName, $decodeEntry];
        }

        // Walk the reachable schema graph to detect any BigInteger /
        // BigDecimal descendant. Done here (not inside the loop above) so
        // the cycle-tracking visiting set is shared across the whole
        // traversal — recursive struct refs only ever recurse through
        // `resolveTarget()`, and we want to avoid re-walking the same
        // target through multiple member paths.
        $visiting = [$schema->id => true];
        $hasBigNumber = self::scanForBigNumber($schema, $visiting);

        // Cache the total member count and prebuild an
        // `array_fill(0, N, null)` template used by the deserializer to
        // size its output array without paying a `Schema::getMemberCount()`
        // + `array_fill` on every nested struct read. PHP arrays are
        // copy-on-write, so handing out the same template to every read
        // is safe: the first slot overwrite triggers the copy and the
        // total per-read allocation is the same. Matches the XML
        // codec's `XmlSchemaExtension::$memberCount / $membersTemplate`.
        $memberCount = \count($byIndex);
        $membersTemplate = $memberCount > 0
            ? \array_fill(0, $memberCount, null)
            : [];

        // Resolved-target pair for struct/union member schemas.
        //
        // Two cases:
        //   - Stub member (no inline members but carries `targetClass`,
        //     the shape codegen emits for recursive references): the wire
        //     shape lives on the canonical schema returned by
        //     `resolveTarget()`. Cache the resolved target schema and its
        //     FQCN so the deserializer's union path doesn't walk
        //     `resolveTarget()` per union instance.
        //
        //   - Canonical schema (has inline members): the wire shape is
        //     right here. `resolvedTargetSchema` is `$schema` itself.
        //
        // We DO NOT eagerly build the target's own {@see JsonSchemaExtension}
        // here. Some codegen'd schema graphs are mutually recursive at the
        // Schema instance level (shape A's stub target has a stub member
        // pointing at B, whose canonical schema has a stub member pointing
        // back at A). Calling `$target->getExtension($key)` inside
        // `provide()` re-enters the provider before the outer `??=` slot
        // in {@see Schema::getExtension} is populated, so the second entry
        // recurses infinitely. The deserializer resolves the target
        // extension lazily in a hot-path-local cache instead — see
        // {@see \Smithy\Json\JsonDeserializer::readUnion}.
        if ($schema->getMemberCount() === 0 && $schema->targetClass !== null) {
            $resolvedTargetSchema = $schema->resolveTarget();
            $resolvedTargetClass = $resolvedTargetSchema->targetClass;
        } else {
            $resolvedTargetSchema = $schema;
            $resolvedTargetClass = $schema->targetClass;
        }

        return new JsonSchemaExtension(
            memberIndexByWireName: $byWireName,
            wireNameByMemberIndex: $byIndex,
            rawMemberIndexByWireName: $rawByWireName,
            rawWireNameByMemberIndex: $rawByIndex,
            memberDecodeByWireName: $memberDecodeByWireName,
            rawMemberDecodeByWireName: $rawMemberDecodeByWireName,
            orderedMembers: $orderedMembers,
            rawOrderedMembers: $rawOrderedMembers,
            orderedMemberCount: \count($orderedMembers),
            timestampFormats: $timestampFormats,
            hasBigNumber: $hasBigNumber,
            memberCount: $memberCount,
            membersTemplate: $membersTemplate,
            resolvedTargetClass: $resolvedTargetClass,
            resolvedTargetSchema: $resolvedTargetSchema,
            unionDecodeByWireName: $unionDecodeByWireName,
            rawUnionDecodeByWireName: $rawUnionDecodeByWireName,
            unionEncodeByMemberName: $unionEncodeByMemberName,
            rawUnionEncodeByMemberName: $rawUnionEncodeByMemberName
        );
    }

    /**
     * Build the lightweight extension carried on a list-typed member
     * schema. Populates the inner shape type plus any list-item-specific
     * decode metadata the deserializer can use to specialize its hot
     * loop; the struct-only wire-name maps stay empty. Matches the shape
     * of `XmlSchemaExtension` for list members — the fields the
     * deserializer reads are directly analogous.
     */
    private function buildListInfo(Schema $schema): JsonSchemaExtension
    {
        $inner = $schema->listMember;
        $innerType = $inner?->type;

        // JSON's timestamp default (both restJson1 and awsJson1.x) is
        // epoch-seconds. Resolve the trait once per list so the
        // deserializer's dedicated timestamp fast-loop can call
        // `$fmt->parse($v)` (or the epoch-numeric branch) without a
        // per-element `TimestampFormat::fromTraitString(...) ??` chain.
        $listTsFmt = null;
        if ($inner !== null && $innerType === ShapeType::Timestamp->value) {
            $listTsFmt = TimestampFormat::fromTraitString(
                $inner->getTrait(Schema::T_TIMESTAMP_FORMAT)
            ) ?? TimestampFormat::EpochSeconds;
        }

        $listMemberResolvedTargetClass = null;
        if (
            $inner !== null
            && (
                $innerType === ShapeType::Enum->value
                || $innerType === ShapeType::IntEnum->value
            )
        ) {
            $listMemberResolvedTargetClass = $inner->targetClass;
            if (
                $listMemberResolvedTargetClass === null
                && $inner->getMemberCount() === 0
            ) {
                $listMemberResolvedTargetClass =
                    $inner->resolveTarget()?->targetClass;
            }
        }

        return new JsonSchemaExtension(
            memberIndexByWireName: [],
            wireNameByMemberIndex: [],
            rawMemberIndexByWireName: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            listMemberType: $innerType,
            listMemberTimestampFormat: $listTsFmt,
            listMemberResolvedTargetClass: $listMemberResolvedTargetClass,
            sparse: $schema->hasTrait(Schema::T_SPARSE)
        );
    }

    /**
     * Build the lightweight extension carried on a map-typed member
     * schema. Populates the value shape type and any map-value-specific
     * decode metadata the deserializer can use to specialize its hot
     * loop, plus the `@sparse` bit for serializer hot paths.
     */
    private function buildMapInfo(Schema $schema): JsonSchemaExtension
    {
        $value = $schema->mapValue;
        $valueType = $value?->type;

        $mapTsFmt = null;
        if ($value !== null && $valueType === ShapeType::Timestamp->value) {
            $mapTsFmt = TimestampFormat::fromTraitString(
                $value->getTrait(Schema::T_TIMESTAMP_FORMAT)
            ) ?? TimestampFormat::EpochSeconds;
        }

        $mapValueResolvedTargetClass = null;
        if (
            $value !== null
            && (
                $valueType === ShapeType::Enum->value
                || $valueType === ShapeType::IntEnum->value
            )
        ) {
            $mapValueResolvedTargetClass = $value->targetClass;
            if (
                $mapValueResolvedTargetClass === null
                && $value->getMemberCount() === 0
            ) {
                $mapValueResolvedTargetClass =
                    $value->resolveTarget()?->targetClass;
            }
        }

        return new JsonSchemaExtension(
            memberIndexByWireName: [],
            wireNameByMemberIndex: [],
            rawMemberIndexByWireName: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            mapValueType: $valueType,
            mapValueTimestampFormat: $mapTsFmt,
            sparse: $schema->hasTrait(Schema::T_SPARSE),
            mapValueResolvedTargetClass: $mapValueResolvedTargetClass
        );
    }

    /**
     * Build the lightweight extension carried on a timestamp-typed
     * member schema. Only the resolved format is populated (defaulted
     * to epoch-seconds per the JSON default) — the struct-only
     * wire-name maps stay empty. Consumed by
     * {@see \Smithy\Json\JsonSerializer::renderTimestamp}, which
     * receives just a member schema and no parent-frame info, so it
     * can't use the struct's `$timestampFormats` map.
     */
    private function buildTimestampInfo(Schema $schema): JsonSchemaExtension
    {
        $fmt = TimestampFormat::fromTraitString(
            $schema->getTrait(Schema::T_TIMESTAMP_FORMAT)
        ) ?? TimestampFormat::EpochSeconds;

        return new JsonSchemaExtension(
            memberIndexByWireName: [],
            wireNameByMemberIndex: [],
            rawMemberIndexByWireName: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            memberTimestampFormat: $fmt
        );
    }

    /**
     * Build the lightweight extension carried on an enum / intEnum
     * member schema. The `resolvedTargetClass` field is pre-resolved
     * from `$schema->targetClass` (or `resolveTarget()->targetClass`
     * for recursive stub members) so
     * {@see \Smithy\Json\JsonDeserializer} can call
     * `$info->resolvedTargetClass::fromValue(...)` directly — no
     * `resolveTarget()` walk on the hot path.
     */
    private function buildEnumMemberInfo(Schema $schema): JsonSchemaExtension
    {
        return new JsonSchemaExtension(
            memberIndexByWireName: [],
            wireNameByMemberIndex: [],
            rawMemberIndexByWireName: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            resolvedTargetClass: $schema->targetClass
                ?? $schema->resolveTarget()?->targetClass
        );
    }

    /**
     * Depth-first scan that returns `true` as soon as a BigInteger or
     * BigDecimal shape is reached via member/list/map edges. Member
     * schemas that carry a `targetClass` (recursive references) are
     * resolved once per traversal and tracked in `$visiting` so cycles
     * terminate at the second encounter.
     *
     * @param array<string, true> $visiting Shape IDs currently on the walk
     *     stack; mutated in-place.
     */
    private static function scanForBigNumber(Schema $schema, array &$visiting): bool
    {
        $type = $schema->type;
        if (
            $type === ShapeType::BigInteger->value
            || $type === ShapeType::BigDecimal->value
        ) {
            return true;
        }

        // Document shapes hold arbitrary runtime values, including
        // `BigIntegerDocument` / `BigDecimalDocument` whose `serialize()`
        // dispatches into `serializeBigInteger` / `serializeBigDecimal`.
        // The schema graph alone can't tell us whether a given Document
        // member will produce a `BigNumberToken` — be conservative and
        // assume yes whenever a Document is reachable.
        if ($type === ShapeType::Document->value) {
            return true;
        }

        // Member schemas with `targetClass` are stubs for recursive
        // references. Resolve once, gate on `$visiting` to break cycles.
        if ($schema->targetClass !== null) {
            $resolved = $schema->resolveTarget();
            if (isset($visiting[$resolved->id])) {
                return false;
            }
            $visiting[$resolved->id] = true;
            $found = self::scanForBigNumber($resolved, $visiting);
            unset($visiting[$resolved->id]);
            return $found;
        }

        if (
            $type === ShapeType::Structure->value
            || $type === ShapeType::Union->value
        ) {
            foreach ($schema->getMembers() as $member) {
                if (self::scanForBigNumber($member, $visiting)) {
                    return true;
                }
            }
            return false;
        }

        if ($type === ShapeType::List_->value) {
            return $schema->listMember !== null
                && self::scanForBigNumber($schema->listMember, $visiting);
        }

        if ($type === ShapeType::Map->value) {
            return $schema->mapValue !== null
                && self::scanForBigNumber($schema->mapValue, $visiting);
        }

        return false;
    }
}
