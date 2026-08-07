<?php

declare(strict_types=1);

namespace Smithy\Json;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableShapeInterface;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\AbstractDocument;

/**
 * Deserialize JSON into Smithy shapes.
 *
 * Decodes the payload once, then traverses the resulting PHP value with
 * schema-guided member dispatch.
 */
final class JsonDeserializer implements ShapeDeserializerInterface
{
    /**
     * Decoded body, structured as nested PHP arrays/scalars. `null` when
     * the wire body was empty or `"null"`.
     */
    private mixed $parsed;

    /**
     * Whether to honor `smithy.api#jsonName` when translating incoming
     * JSON keys to member indices. `true` matches `restJson1`; `false`
     * (used by `awsJson1_x`) directs the lookup at the extension's
     * `raw*` maps instead. Consulted only at struct-open time so the
     * per-key fast path stays a single hash probe.
     */
    private readonly bool $honorJsonName;

    /**
     * Parse a JSON wire body for schema-driven deserialization.
     */
    public function __construct(string $source, bool $honorJsonName = true)
    {
        $this->honorJsonName = $honorJsonName;
        if ($source === '') {
            $this->parsed = null;
            return;
        }
        $decoded = \json_decode($source, true, 512, \JSON_BIGINT_AS_STRING);
        if ($decoded === null && \json_last_error() !== \JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'JSON deserialization failed: ' . \json_last_error_msg()
            );
        }
        $this->parsed = $decoded;
    }

    /**
     * Deserialize the parsed wire body into positional schema members.
     *
     * @param array<int, mixed> $members
     *
     * @return void
     */
    public function deserialize(Schema $schema, array &$members): void
    {
        if ($this->parsed === null) {
            return;
        }
        // @httpPayload union bodies: the entire JSON document is the union.
        // `readUnion` returns the hydrated variant instance; the protocol
        // layer reads $members[0] and drops it into the payload slot.
        if ($schema->type === ShapeType::Union->value) {
            if (\is_array($this->parsed) && $this->parsed !== []) {
                $member = new Schema(
                    id: $schema->id,
                    type: ShapeType::Union,
                    members: [],
                    targetClass: $schema->targetClass
                );
                $members[0] = $this->readUnion($member, $this->parsed);
            }
            return;
        }

        if (!\is_array($this->parsed)) {
            // A non-object top-level body (a JSON number or string) cannot
            // populate a struct. Leave $members untouched.
            return;
        }
        $this->readStruct($schema, $this->parsed, $members);
    }

    /**
     * Walk a JSON object into the positional `$members` array for `$schema`.
     *
     * @param array<string, mixed> $object Decoded JSON object.
     * @param array<int, mixed>    $members Positional output, modified in place.
     */
    private function readStruct(Schema $schema, array $object, array &$members): void
    {
        // Local-static cache of the extension key. See
        // JsonSerializer::beginStruct for the rationale: it collapses
        // per-call method dispatch to a single null-coalesce on an
        // already-populated method-local.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        if ($info === null) {
            return;
        }

        // Wire-name map pick: honor `@jsonName` (restJson1) or ignore it
        // (awsJson1_x) by choosing between the default and `raw*` variants
        // once per struct-open. Both the ordered fast-path list and the
        // hash-map fallback are picked in lockstep so the lookup shape is
        // identical from the loop's perspective.
        $ordered = $this->honorJsonName
            ? $info->orderedMembers
            : $info->rawOrderedMembers;
        $memberDecodeByWireName = $this->honorJsonName
            ? $info->memberDecodeByWireName
            : $info->rawMemberDecodeByWireName;
        // Speculative in-order lookup fast path (borrowed from smithy-java's
        // `SmithyMemberLookup` `expectedNext` hint): when JSON keys arrive in
        // schema declaration order — the common case for AWS responses,
        // since codegen emits members in declaration order and servers
        // typically emit fields that way — the next expected member matches
        // with a single string compare against `$ordered[$hint][0]`. On miss
        // we fall back to the hashmap probe, then try to nudge the hint
        // forward with a bounded scan so a single out-of-order key doesn't
        // poison the rest of the loop.
        $orderedCount = $info->orderedMemberCount;
        $hint = 0;
        foreach ($object as $key => $value) {
            // Fast path: speculative match against the next expected member.
            if ($hint < $orderedCount && $ordered[$hint][0] === $key) {
                $entry = $ordered[$hint][1];
                $hint++;
            } else {
                // Skip wire keys the schema doesn't know about — forward-compat
                // with newer service versions adding members. `isset` is a
                // direct C-level hash probe against the precomputed
                // wire-name-to-member-schema map, so we resolve the member
                // in one lookup instead of the previous two-step
                // (index lookup + `getMemberByIndex`).
                $entry = $memberDecodeByWireName[$key] ?? null;
                if ($entry === null) {
                    continue;
                }
                // Try to advance the hint past the matched member so
                // subsequent in-order keys continue hitting the fast path.
                // Bounded scan matches the XML deserializer's approach —
                // the common "one out-of-order key" case resolves in a
                // handful of comparisons; a fully-scrambled document just
                // pays the full-lookup cost on every key.
                $scanLimit = $hint + 4 < $orderedCount
                    ? $hint + 4
                    : $orderedCount;
                for ($i = $hint; $i < $scanLimit; $i++) {
                    if ($ordered[$i][0] === $key) {
                        $hint = $i + 1;
                        break;
                    }
                }
            }

            $idx = $entry[0];
            if ($value === null) {
                $members[$idx] = null;
                continue;
            }

            switch ($entry[1]) {
                case 10: // String_
                    $members[$idx] = (string) $value;
                    break;
                case 17: // Enum
                    $t = $entry[2];
                    $members[$idx] = $t !== null
                        ? $t::fromValue((string) $value)
                        : (string) $value;
                    break;
                case 1: // Boolean
                    $members[$idx] = (bool) $value;
                    break;
                case 4: // Integer
                case 5: // Long
                case 3: // Short
                case 2: // Byte
                    $members[$idx] = (int) $value;
                    break;
                case 18: // IntEnum
                    $t = $entry[2];
                    $intValue = (int) $value;
                    $members[$idx] = $t !== null
                        ? $t::fromValue($intValue)
                        : $intValue;
                    break;
                case 6: // Float_
                case 7: // Double
                    $members[$idx] = match ($value) {
                        'NaN' => \NAN,
                        'Infinity' => \INF,
                        '-Infinity' => -\INF,
                        default => (float) $value,
                    };
                    break;
                case 8: // BigInteger
                    $members[$idx] = new BigInteger((string) $value);
                    break;
                case 9: // BigDecimal
                    $members[$idx] = new BigDecimal((string) $value);
                    break;
                case 11: // Blob
                    $members[$idx] =
                        \base64_decode((string) $value, true) ?: '';
                    break;
                case 12: // Timestamp
                    $fmt = $entry[2];
                    $members[$idx] = $fmt === TimestampFormat::EpochSeconds
                        ? ((\is_int($value) || \is_float($value))
                            ? (float) $value
                            : (float) (string) $value)
                        : $fmt->parse((string) $value);
                    break;
                case 15: // Structure
                    $members[$idx] = $this->readNestedStruct($entry[2], $value);
                    break;
                case 16: // Union
                    $members[$idx] = $this->readUnion($entry[2], $value);
                    break;
                case 13: // List_
                    $members[$idx] = $this->readList($entry[2], $value);
                    break;
                case 14: // Map
                    $members[$idx] = $this->readMap($entry[2], $value);
                    break;
                case 19: // Document
                    $members[$idx] = AbstractDocument::fromValue($value);
                    break;
                default:
                    $members[$idx] = $value;
                    break;
            }
        }
    }
    /**
     * Hydrate a nested struct into a typed shape instance. `fromMembers`
     * is a `new self(...$members)` spread now, so this method owns the
     * lift from the dense positional array to the typed object.
     *
     * # Fast-path shape
     *
     * `$member` here is typically the member schema for a struct-typed
     * field. Member schemas rendered by codegen carry the full member list
     * inline, so the extension's `memberCount > 0` is the common case and
     * the `resolveTarget()` call is only taken for recursive stub
     * references (member with `targetClass` but no inline members).
     *
     * The pre-built `$membersTemplate` on the resolved schema's extension
     * lets us skip the `getMemberCount()` + `array_fill(0, N, null)` on
     * every read. PHP arrays are copy-on-write, so assigning the template
     * and then having `readStruct` write into `$members` triggers exactly
     * one copy — same allocation cost as `array_fill` but with the fill
     * itself hoisted to provider time and shared across all reads of the
     * same struct type. Mirrors {@see \Smithy\Xml\XmlDeserializer::readNestedStructFromElement}.
     */
    private function readNestedStruct(
        Schema $member,
        mixed $value
    ): ?SerializableShapeInterface
    {
        if (!\is_array($value)) {
            return null;
        }
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();

        // Resolve recursive stub references via the extension's cached
        // `$memberCount`. Reading a readonly int property beats the
        // `getMemberCount()` method dispatch + count() + `??=` cache on
        // every nested-struct frame — even though that method is cheap,
        // this path fires on every deeply-nested list item.
        /** @var JsonSchemaExtension|null $info */
        $info = $member->getExtension($extKey);
        if ($info === null || $info->memberCount === 0) {
            $targetSchema = $member->resolveTarget();
            $info = $targetSchema->getExtension($extKey);
        } else {
            $targetSchema = $member;
        }

        // `$info` should be non-null for a struct/union target after
        // the resolveTarget() fallback; the provider builds a struct info
        // for both. Fall back to the old `array_fill` path defensively
        // for the "no extension" corner (shouldn't happen — the codec
        // only calls this for struct-typed fields).
        if ($info !== null) {
            $members = $info->membersTemplate;
        } else {
            $count = $targetSchema->getMemberCount();
            $members = $count > 0 ? \array_fill(0, $count, null) : [];
        }
        $this->readStruct($targetSchema, $value, $members);
        return $targetSchema->targetClass
            ? ($targetSchema->targetClass)::fromMembers($members)
            : null;
    }



    /**
     * Hydrate a union wire value into a typed variant instance via the
     * generated `create($tag, $value)` / `unknown($tag, $value)` factory.
     */
    private function readUnion(Schema $member, mixed $value): ?SerializableShapeInterface
    {
        if (!\is_array($value) || $value === []) {
            return null;
        }
        // Local-static extension key. Hot on any nested-union walk
        // (readMap → decodeItem → readUnion recursion for DynamoDB-style
        // AttributeValue trees).
        //
        // The member's own extension carries two pre-resolved fields
        // populated by {@see JsonSchemaExtensionProvider} at build time:
        //
        //   - `resolvedTargetClass`  — the factory FQCN to call
        //     `::create()` / `::unknown()` on.
        //   - `resolvedTargetSchema` — the canonical target Schema (used
        //     for the fallback `unknown()` factory call that expects
        //     `->targetClass` on the canonical shape, and for the
        //     target-extension lookup below).
        //
        // The target's own {@see JsonSchemaExtension} — which carries
        // the wire-name maps for the union's variants — is fetched
        // lazily and memoized in a method-local static keyed by target
        // schema `id`. The eager version (fetching the target extension
        // inside `provide()`) cycles on mutually-recursive schema
        // graphs where two shape stubs point at each other; deferring
        // to first `readUnion` invocation avoids the re-entrance,
        // because the outer `Schema::getExtension` `??=` slot is
        // populated by the time we get here.
        //
        // The old code paid a second `$unionSchema->getExtension($extKey)`
        // per union instance to fetch the target's wire-name maps. On
        // DynamoDB `GetItemOutput_L` that's ~830 samples of pure overhead
        // per response — the pattern this cache eliminates. After the
        // first union of each type is decoded, subsequent instances hit
        // the local-static array with an int-keyed offset probe.
        static $extKey = null;
        static $targetInfoById = [];
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $memberInfo */
        $memberInfo = $member->getExtension($extKey);
        if ($memberInfo === null) {
            // Synthetic runtime schema with no cached extension — walk
            // `resolveTarget()` on the slow path. Never fires on codegen
            // output; only reachable when a test builds a union member
            // schema without going through the provider.
            $unionSchema = $member->resolveTarget();
            $targetClass = $unionSchema->targetClass;
            if ($targetClass === null) {
                return null;
            }
            $info = $unionSchema->getExtension($extKey);
        } else {
            $targetClass = $memberInfo->resolvedTargetClass;
            if ($targetClass === null) {
                return null;
            }
            $unionSchema = $memberInfo->resolvedTargetSchema ?? $member;
            // When the member schema IS the canonical target (it carries
            // the full member list inline), its own extension already has
            // the wire-name maps — no second fetch needed.
            if ($unionSchema === $member) {
                $info = $memberInfo;
            } else {
                $tid = $unionSchema->id;
                $info = $targetInfoById[$tid]
                    ??= $unionSchema->getExtension($extKey);
            }
        }

        // Same wire-name pick as `readStruct`: honor `@jsonName` under
        // restJson1, ignore it under awsJson1_x.
        $unionDecodeByWireName = $info === null
            ? []
            : ($this->honorJsonName
                ? $info->unionDecodeByWireName
                : $info->rawUnionDecodeByWireName);

        foreach ($value as $wireKey => $wireValue) {
            // restJson1 `__type` is a server-side discriminator; clients
            // ignore it.
            if ($wireKey === '__type') {
                continue;
            }
            // Servers are allowed to send `variantName: null` for every
            // absent variant (see the `AwsJson10DeserializeAllowNulls` and
            // `AwsJson11DeserializeAllowNulls` compliance cases). A union
            // variant is only "present" when the wire value is non-null;
            // otherwise skip and keep looking for the real variant. This
            // also mirrors the smithy-lang reference SDKs.
            if ($wireValue === null) {
                continue;
            }
            $entry = $unionDecodeByWireName[$wireKey] ?? null;

            if ($entry === null) {
                return $targetClass::unknown((string) $wireKey, $wireValue);
            }

            switch ($entry[1]) {
                case 10:
                    return $targetClass::create($entry[0], (string) $wireValue);

                case 17:
                    $t = $entry[2] ?? null;
                    return $targetClass::create(
                        $entry[0],
                        $t !== null
                            ? $t::fromValue((string) $wireValue)
                            : (string) $wireValue
                    );

                case 1:
                    return $targetClass::create($entry[0], (bool) $wireValue);

                case 4:
                case 5:
                case 3:
                case 2:
                    return $targetClass::create($entry[0], (int) $wireValue);

                case 18:
                    $t = $entry[2] ?? null;
                    return $targetClass::create(
                        $entry[0],
                        $t !== null
                            ? $t::fromValue((int) $wireValue)
                            : (int) $wireValue
                    );

                case 6:
                case 7:
                    return $targetClass::create(
                        $entry[0],
                        match ($wireValue) {
                            'NaN' => \NAN,
                            'Infinity' => \INF,
                            '-Infinity' => -\INF,
                            default => (float) $wireValue,
                        },
                    );

                case 8:
                    return $targetClass::create(
                        $entry[0],
                        new BigInteger((string) $wireValue)
                    );

                case 9:
                    return $targetClass::create(
                        $entry[0],
                        new BigDecimal((string) $wireValue)
                    );

                case 11:
                    return $targetClass::create(
                        $entry[0],
                        \base64_decode((string) $wireValue, true) ?: ''
                    );

                case 12:
                    $fmt = $entry[2] ?? TimestampFormat::EpochSeconds;
                    return $targetClass::create(
                        $entry[0],
                        $fmt === TimestampFormat::EpochSeconds
                            ? ((\is_int($wireValue) || \is_float($wireValue))
                                ? (float) $wireValue
                                : (float) (string) $wireValue)
                            : $fmt->parse((string) $wireValue),
                    );

                case 15:
                    return $targetClass::create(
                        $entry[0],
                        $this->readNestedStruct($entry[2], $wireValue)
                    );

                case 16:
                    return $targetClass::create(
                        $entry[0],
                        $this->readUnion($entry[2], $wireValue)
                    );

                case 13:
                    return $targetClass::create(
                        $entry[0],
                        $this->readList($entry[2], $wireValue)
                    );

                case 14:
                    return $targetClass::create(
                        $entry[0],
                        $this->readMap($entry[2], $wireValue)
                    );

                case 19:
                    return $targetClass::create(
                        $entry[0],
                        AbstractDocument::fromValue($wireValue)
                    );

                default:
                    return $targetClass::create($entry[0], $wireValue);
            }
        }
        return null;
    }


    /**
     * Deserialize a JSON array under `$member` (a list-typed member schema).
     *
     * # Fast-path shape
     *
     * The list's inner shape type is precomputed on the member's
     * {@see JsonSchemaExtension::$listMemberType} at provider time, so
     * scalar-item lists (strings, ints, floats, booleans, timestamps,
     * blobs, enums, intEnums, bignums) take a literal-int dispatch to
     * specialized inline loops that skip the `decodeItem` method-call
     * frame entirely. Nested list/map/struct/union/document items also
     * route directly to their dedicated helpers; any unknown synthetic
     * inner type falls back to raw passthrough.
     *
     * Timestamp lists are the biggest win: the format was resolved once
     * by the provider (default epoch-seconds per Smithy JSON spec) and
     * hangs off the outer list's extension as
     * {@see JsonSchemaExtension::$listMemberTimestampFormat}. That skips
     * the per-element `TimestampFormat::fromTraitString(...) ??` chain
     * in `readTimestampStandalone` for every element of large timestamp
     * lists — same pattern the XML deserializer uses for
     * `GetMetricDataResult.Timestamps`.
     */
    private function readList(Schema $member, mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $itemSchema = $member->listMember;
        if ($itemSchema === null) {
            // No inner schema — pass through unchanged (defensive for
            // synthetic schemas built at runtime).
            return $value;
        }

        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $member->getExtension($extKey);
        // Fall back to reading the inner shape type off the item schema
        // when no extension is available (synthetic list schemas without
        // an inner member trait).
        $memberType = $info?->listMemberType ?? $itemSchema->type;

        $out = [];
        switch ($memberType) {
            case 10:  // ShapeType::String_
                foreach ($value as $item) {
                    $out[] = $item === null ? null : (string) $item;
                }
                break;

            case 4:  // ShapeType::Integer
            case 5:  // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                foreach ($value as $item) {
                    $out[] = $item === null ? null : (int) $item;
                }
                break;

            case 1: // ShapeType::Boolean
                foreach ($value as $item) {
                    $out[] = $item === null ? null : (bool) $item;
                }
                break;

            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                foreach ($value as $item) {
                    if ($item === null) {
                        $out[] = null;
                        continue;
                    }
                    $out[] = match ($item) {
                            'NaN' => \NAN,
                            'Infinity' => \INF,
                            '-Infinity' => -\INF,
                            default => (float) $item,
                        };
                }
                break;

            case 12: // ShapeType::Timestamp
                // Dedicated timestamp fast-loop. The format is resolved
                // once by the provider (default epoch-seconds); the loop
                // body specializes on the epoch branch so no format check
                // fires per element.
                $fmt = $info?->listMemberTimestampFormat
                    ?? TimestampFormat::EpochSeconds;
                if ($fmt === TimestampFormat::EpochSeconds) {
                    foreach ($value as $item) {
                        if ($item === null) {
                            $out[] = null;
                            continue;
                        }
                        $out[] = \is_int($item) || \is_float($item)
                            ? (float) $item
                            : (float) (string) $item;
                    }
                } else {
                    // http-date / date-time strings.
                    foreach ($value as $item) {
                        $out[] = $item === null
                            ? null
                            : $fmt->parse((string) $item);
                    }
                }
                break;

            case 11: // ShapeType::Blob
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : (\base64_decode((string) $item, true) ?: '');
                }
                break;

            case 17: // ShapeType::Enum
                $target = $info?->listMemberResolvedTargetClass;
                if ($target === null) {
                    foreach ($value as $item) {
                        $out[] = $item === null ? null : (string) $item;
                    }
                } else {
                    foreach ($value as $item) {
                        $out[] = $item === null
                            ? null
                            : $target::fromValue((string) $item);
                    }
                }
                break;

            case 18: // ShapeType::IntEnum
                $target = $info?->listMemberResolvedTargetClass;
                if ($target === null) {
                    foreach ($value as $item) {
                        $out[] = $item === null ? null : (int) $item;
                    }
                } else {
                    foreach ($value as $item) {
                        $out[] = $item === null
                            ? null
                            : $target::fromValue((int) $item);
                    }
                }
                break;

            case 8: // ShapeType::BigInteger
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : new BigInteger((string) $item);
                }
                break;

            case 9: // ShapeType::BigDecimal
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : new BigDecimal((string) $item);
                }
                break;

            case 15: // ShapeType::Structure
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : $this->readNestedStruct($itemSchema, $item);
                }
                break;

            case 16: // ShapeType::Union
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : $this->readUnion($itemSchema, $item);
                }
                break;

            case 13: // ShapeType::List_
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : $this->readList($itemSchema, $item);
                }
                break;

            case 14: // ShapeType::Map
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : $this->readMap($itemSchema, $item);
                }
                break;

            case 19: // ShapeType::Document
                foreach ($value as $item) {
                    $out[] = $item === null
                        ? null
                        : AbstractDocument::fromValue($item);
                }
                break;

            default:
                return $value;
        }
        return $out;
    }


    private function readMap(Schema $member, mixed $value): array
    {
        // `json_decode($source, true)` (used by the constructor) always
        // hydrates JSON objects as PHP arrays — never `stdClass`. The
        // associative-array branch is the only path we ever take.
        if (!\is_array($value)) {
            return [];
        }

        $valueSchema = $member->mapValue;
        if ($valueSchema === null) {
            return $value;
        }

        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $member->getExtension($extKey);
        $valueType = $info?->mapValueType ?? $valueSchema->type;

        $out = [];
        switch ($valueType) {
            case 10: // ShapeType::String_
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null ? null : (string) $v;
                }
                break;

            case 4: // ShapeType::Integer
            case 5: // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null ? null : (int) $v;
                }
                break;

            case 1: // ShapeType::Boolean
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null ? null : (bool) $v;
                }
                break;

            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                foreach ($value as $key => $v) {
                    if ($v === null) {
                        $out[$key] = null;
                        continue;
                    }
                    $out[$key] = match ($v) {
                        'NaN' => \NAN,
                        'Infinity' => \INF,
                        '-Infinity' => -\INF,
                        default => (float) $v,
                    };
                }
                break;

            case 12: // ShapeType::Timestamp
                $fmt = $info?->mapValueTimestampFormat
                    ?? TimestampFormat::EpochSeconds;
                if ($fmt === TimestampFormat::EpochSeconds) {
                    foreach ($value as $key => $v) {
                        if ($v === null) {
                            $out[$key] = null;
                            continue;
                        }
                        $out[$key] = \is_int($v) || \is_float($v)
                            ? (float) $v
                            : (float) (string) $v;
                    }
                } else {
                    foreach ($value as $key => $v) {
                        $out[$key] = $v === null
                            ? null
                            : $fmt->parse((string) $v);
                    }
                }
                break;

            case 11: // ShapeType::Blob
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : (\base64_decode((string) $v, true) ?: '');
                }
                break;

            case 17: // ShapeType::Enum
                $target = $info?->mapValueResolvedTargetClass;
                if ($target === null) {
                    foreach ($value as $key => $v) {
                        $out[$key] = $v === null ? null : (string) $v;
                    }
                } else {
                    foreach ($value as $key => $v) {
                        $out[$key] = $v === null
                            ? null
                            : $target::fromValue((string) $v);
                    }
                }
                break;

            case 18: // ShapeType::IntEnum
                $target = $info?->mapValueResolvedTargetClass;
                if ($target === null) {
                    foreach ($value as $key => $v) {
                        $out[$key] = $v === null ? null : (int) $v;
                    }
                } else {
                    foreach ($value as $key => $v) {
                        $out[$key] = $v === null
                            ? null
                            : $target::fromValue((int) $v);
                    }
                }
                break;

            case 8: // ShapeType::BigInteger
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : new BigInteger((string) $v);
                }
                break;

            case 9: // ShapeType::BigDecimal
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : new BigDecimal((string) $v);
                }
                break;

            case 15: // ShapeType::Structure
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : $this->readNestedStruct($valueSchema, $v);
                }
                break;

            case 16: // ShapeType::Union
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : $this->readUnion($valueSchema, $v);
                }
                break;

            case 13: // ShapeType::List_
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : $this->readList($valueSchema, $v);
                }
                break;

            case 14: // ShapeType::Map
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : $this->readMap($valueSchema, $v);
                }
                break;

            case 19: // ShapeType::Document
                foreach ($value as $key => $v) {
                    $out[$key] = $v === null
                        ? null
                        : AbstractDocument::fromValue($v);
                }
                break;

            default:
                return $value;
        }

        return $out;
    }
}
