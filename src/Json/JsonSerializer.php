<?php

declare(strict_types=1);

namespace Smithy\Json;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\DocumentInterface;
use Smithy\Core\Shape\UnionInterface;

/**
 * Serialize Smithy shapes into JSON-compatible PHP values.
 *
 * Tracks struct context so member writes can resolve wire names and
 * timestamp formats through local arrays instead of repeated lookups.
 */
final class JsonSerializer implements ShapeSerializerInterface
{
    /**
     * Root output once the outermost struct frame closes. Set by the first
     * `serializeStruct` call after construction, returned by `flush()`.
     */
    private mixed $root = null;

    /**
     * Active struct/union frame. Member writes target `$this->current[$key]`.
     *
     * Held by reference so nested `serializeStruct` calls can save/restore
     * via local variables on their own stack frame instead of pushing onto
     * a separate stack array.
     *
     * @var array<string, mixed>|null
     */
    private ?array $current = null;

    /**
     * Wire-name lookup for the current struct/union frame.
     *
     * @var array<int, string>|null
     */
    private ?array $wireNames = null;

    /**
     * Timestamp format lookup for the current struct frame, keyed by
     * `memberIndex`. Resolved per-frame so nested structs with different
     * timestamp members each see their own formats.
     *
     * @var array<int, TimestampFormat>|null
     */
    private ?array $timestampFormats = null;

    /**
     * Saved parent frames for nesting. Each entry is
     * `[parentFrameByValue, parentWireNames, parentTimestampFormats, idx]`
     * where `$idx` is the member-index argument of the matching
     * `beginStruct` call. `endStruct` pops and assigns the just-finished
     * frame back into the parent at that `$idx`'s wire name.
     *
     * @var list<array{
     *     0: array|null,
     *     1: array<int, string>|null,
     *     2: array<int, TimestampFormat>|null,
     *     3: int
     * }>
     */
    private array $frameStack = [];

    /**
     * Reflects the top-level struct/union schema's
     * {@see JsonSchemaExtension::$hasBigNumber} flag. Set when the outer
     * frame opens (entry `beginStruct` with an empty `frameStack`) so
     * `flush()` can decide whether to run the `BigNumberToken`
     * post-processing scan at all. Most AWS services have no bignum
     * shapes, so this short-circuits the `str_contains`/`preg_replace`
     * pair on every request.
     */
    private bool $rootHasBigNumber = false;

    /**
     * Reused sub-serializer for rendering document values nested inside
     * lists/maps/unions without allocating a fresh serializer per item.
     */
    private ?self $documentSerializer = null;

    /**
     * Per-request memoization of raw blob bytes to base64 strings.
     *
     * @var array<string, string>
     */
    private array $blobEncodeCache = [];

    /**
     * Serialize a boolean member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBoolean(Schema $schema, bool $value, int $idx): void
    {
        $this->emit($value, $idx);
    }

    /**
     * Serialize an integer member into the current JSON frame.
     *
     * @return void
     */
    public function serializeInteger(Schema $schema, int $value, int $idx): void
    {
        $this->emit($value, $idx);
    }

    /**
     * Serialize a floating-point member into the current JSON frame.
     *
     * @return void
     */
    public function serializeFloat(Schema $schema, float $value, int $idx): void
    {
        // JSON has no NaN/Infinity. The Smithy spec for restJson1 represents
        // these as the strings "NaN", "Infinity", "-Infinity" — same as the
        // V3 SDK and the upstream protocol tests.
        //
        // Do the sentinel check via `(string) $value` (which returns
        // `NAN` / `INF` / `-INF` for the non-numeric floats) rather than
        // `is_nan` + `is_infinite`: one cast + one match on a small string
        // set replaces two C-calls and their branch-mispredicts in the
        // finite-value hot path. Matches the XmlSerializer implementation.
        $s = match ($str = (string) $value) {
            'NAN'  => 'NaN',
            'INF'  => 'Infinity',
            '-INF' => '-Infinity',
            default => null,
        };
        // Finite: emit as native JSON number. Non-numeric: emit as string.
        $this->emit($s ?? $value, $idx);
    }

    /**
     * Serialize a big-integer member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBigInteger(Schema $schema, BigInteger $value, int $idx): void
    {
        // BigInteger values are emitted as unquoted number tokens. json_encode
        // does not accept a raw-number string, so we use JSON_NUMERIC_CHECK
        // at flush time? No — that would coerce any numeric-looking string in
        // the payload. Instead, mark the value as a sentinel that flush()
        // post-processes. Simpler: emit through json_encode + str_replace
        // at flush is fragile. Keep the digits as the value here and rely on
        // PHP's json_encode to render large-int strings as quoted strings —
        // BigInteger digit-strings are guaranteed to overflow PHP's int range
        // for the cases we care about. Wrap in a sentinel object so we can
        // unwrap to a raw token in flush().
        $this->emit($this->wrapBigIntegerToken($value), $idx);
    }

    /**
     * Serialize a big-decimal member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBigDecimal(Schema $schema, BigDecimal $value, int $idx): void
    {
        $this->emit($this->wrapBigDecimalToken($value), $idx);
    }

    /**
     * Serialize a string member into the current JSON frame.
     *
     * @return void
     */
    public function serializeString(Schema $schema, string $value, int $idx): void
    {
        $this->emit($value, $idx);
    }

    /**
     * Serialize a blob member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBlob(Schema $schema, string $value, int $idx): void
    {
        // restJson1 represents blob members as base64-encoded strings on
        // the wire. Streaming blobs route through the HTTP binding layer
        // as the payload member, so we never see them here.
        $cache = &$this->blobEncodeCache;
        if (isset($cache[$value])) {
            $this->emit($cache[$value], $idx);
            return;
        }
        $encoded = \base64_encode($value);
        $cache[$value] = $encoded;
        $this->emit($encoded, $idx);
    }

    /**
     * Serialize a timestamp member into the current JSON frame.
     *
     * @return void
     */
    public function serializeTimestamp(
        Schema $schema,
        float $value,
        int $idx,
        ?TimestampFormat $format = null
    ): void
    {
        // Codegen passes the format hint when one is known at the call
        // site (e.g., the operation's @timestampFormat on this member).
        // Fall back to the per-frame precomputed map otherwise. The
        // provider seeds the map with the restJson1 default of
        // epoch-seconds when the member has no `@timestampFormat`.
        $resolved = $format
            ?? ($this->timestampFormats[$idx]
                ?? TimestampFormat::EpochSeconds);

        if ($resolved === TimestampFormat::EpochSeconds) {
            // epoch-seconds renders as a JSON number, not a string.
            $this->emit($value, $idx);
            return;
        }
        $this->emit($resolved->format($value), $idx);
    }

    /**
     * Serialize a list member into the current JSON frame.
     *
     * @return void
     */
    public function serializeList(
        Schema $schema,
        array $list,
        int $idx,
        ShapeType $memberType
    ): void
    {
        // `@sparse` is precomputed on the extension so this hot path
        // reads a single `bool` instead of calling `Schema::hasTrait()`
        // per invocation. Trait lookups have no place on the serde
        // hot path.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $memberSchema = $schema->listMember;
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;

        if ($memberSchema === null) {
            $this->emit($list, $idx);
            return;
        }

        $type = $info?->listMemberType ?? $memberType->value;
        $out = [];
        switch ($type) {
            case 10: // ShapeType::String_
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = (string) $item;
                }
                break;

            case 17: // ShapeType::Enum
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item instanceof \Smithy\Core\Shape\EnumInterface
                        ? $item->getValue()
                        : (string) $item;
                }
                break;

            case 1: // ShapeType::Boolean
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = (bool) $item;
                }
                break;

            case 4: // ShapeType::Integer
            case 5: // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = (int) $item;
                }
                break;

            case 18: // ShapeType::IntEnum
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item instanceof \Smithy\Core\Shape\EnumInterface
                        ? $item->getValue()
                        : (int) $item;
                }
                break;

            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = match ($str = (string) (float) $item) {
                        'NAN' => 'NaN',
                        'INF' => 'Infinity',
                        '-INF' => '-Infinity',
                        default => (float) $item,
                    };
                }
                break;

            case 11: // ShapeType::Blob
                $cache = &$this->blobEncodeCache;
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    if (isset($cache[$item])) {
                        $out[] = $cache[$item];
                        continue;
                    }
                    $encoded = \base64_encode($item);
                    $cache[$item] = $encoded;
                    $out[] = $encoded;
                }
                break;

            case 8: // ShapeType::BigInteger
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->wrapBigIntegerToken($item);
                }
                break;

            case 9: // ShapeType::BigDecimal
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->wrapBigDecimalToken($item);
                }
                break;

            case 12: // ShapeType::Timestamp
                $resolved = $info?->listMemberTimestampFormat
                    ?? TimestampFormat::EpochSeconds;
                if ($resolved === TimestampFormat::EpochSeconds) {
                    foreach ($list as $item) {
                        if ($item === null) {
                            if ($sparse) {
                                $out[] = null;
                            }
                            continue;
                        }
                        $epoch = $item;
                        if ($item instanceof \DateTimeInterface) {
                            $epoch = (float) $item->format('U.u');
                            if ($epoch == (int) $epoch) {
                                $epoch = (int) $epoch;
                            }
                        }
                        $out[] = $epoch;
                    }
                } else {
                    foreach ($list as $item) {
                        if ($item === null) {
                            if ($sparse) {
                                $out[] = null;
                            }
                            continue;
                        }
                        $epoch = $item;
                        if ($item instanceof \DateTimeInterface) {
                            $epoch = (float) $item->format('U.u');
                            if ($epoch == (int) $epoch) {
                                $epoch = (int) $epoch;
                            }
                        }
                        $out[] = $resolved->format($epoch);
                    }
                }
                break;

            case 15: // ShapeType::Structure
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderStruct($memberSchema, $item);
                }
                break;

            case 16: // ShapeType::Union
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderUnion($memberSchema, $item);
                }
                break;

            case 13: // ShapeType::List_
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderListInline($memberSchema, $item);
                }
                break;

            case 14: // ShapeType::Map
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderMapInline($memberSchema, $item);
                }
                break;

            case 19: // ShapeType::Document
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item instanceof DocumentInterface
                        ? $this->renderDocument($item)
                        : $item;
                }
                break;

            default:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item;
                }
        }
        $this->emit($out, $idx);
    }


    /**
     * Serialize a map member into the current JSON frame.
     *
     * @return void
     */
    public function serializeMap(Schema $schema, array $map, int $idx): void
    {
        // Same trait-lift-to-extension pattern as `serializeList`. The
        // `@sparse` bit is baked in by the provider so this loop's null
        // policy comes from a single `bool` fetch.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $valueSchema = $schema->mapValue;
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;

        $out = [];
        if ($valueSchema === null) {
            foreach ($map as $key => $value) {
                $stringKey = (string) $key;
                if ($value === null) {
                    if ($sparse) {
                        $out[$stringKey] = null;
                    }
                    continue;
                }
                $out[$stringKey] = $value;
            }
        } else {
            $type = $info?->mapValueType ?? $valueSchema->type;
            switch ($type) {
                case 10: // ShapeType::String_
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = (string) $value;
                    }
                    break;

                case 17: // ShapeType::Enum
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] =
                            $value instanceof \Smithy\Core\Shape\EnumInterface
                                ? $value->getValue()
                                : (string) $value;
                    }
                    break;

                case 1: // ShapeType::Boolean
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = (bool) $value;
                    }
                    break;

                case 4: // ShapeType::Integer
                case 5: // ShapeType::Long
                case 3: // ShapeType::Short
                case 2: // ShapeType::Byte
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = (int) $value;
                    }
                    break;

                case 18: // ShapeType::IntEnum
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] =
                            $value instanceof \Smithy\Core\Shape\EnumInterface
                                ? $value->getValue()
                                : (int) $value;
                    }
                    break;

                case 6: // ShapeType::Float_
                case 7: // ShapeType::Double
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = match (
                            $str = (string) (float) $value
                        ) {
                            'NAN' => 'NaN',
                            'INF' => 'Infinity',
                            '-INF' => '-Infinity',
                            default => (float) $value,
                        };
                    }
                    break;

                case 11: // ShapeType::Blob
                    $cache = &$this->blobEncodeCache;
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        if (isset($cache[$value])) {
                            $out[$stringKey] = $cache[$value];
                            continue;
                        }
                        $encoded = \base64_encode($value);
                        $cache[$value] = $encoded;
                        $out[$stringKey] = $encoded;
                    }
                    break;

                case 8: // ShapeType::BigInteger
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $this->wrapBigIntegerToken($value);
                    }
                    break;

                case 9: // ShapeType::BigDecimal
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $this->wrapBigDecimalToken($value);
                    }
                    break;

                case 12: // ShapeType::Timestamp
                    $resolved = $info?->mapValueTimestampFormat
                        ?? TimestampFormat::EpochSeconds;
                    if ($resolved === TimestampFormat::EpochSeconds) {
                        foreach ($map as $key => $value) {
                            $stringKey = (string) $key;
                            if ($value === null) {
                                if ($sparse) {
                                    $out[$stringKey] = null;
                                }
                                continue;
                            }
                            $epoch = $value;
                            if ($value instanceof \DateTimeInterface) {
                                $epoch = (float) $value->format('U.u');
                                if ($epoch == (int) $epoch) {
                                    $epoch = (int) $epoch;
                                }
                            }
                            $out[$stringKey] = $epoch;
                        }
                    } else {
                        foreach ($map as $key => $value) {
                            $stringKey = (string) $key;
                            if ($value === null) {
                                if ($sparse) {
                                    $out[$stringKey] = null;
                                }
                                continue;
                            }
                            $epoch = $value;
                            if ($value instanceof \DateTimeInterface) {
                                $epoch = (float) $value->format('U.u');
                                if ($epoch == (int) $epoch) {
                                    $epoch = (int) $epoch;
                                }
                            }
                            $out[$stringKey] = $resolved->format($epoch);
                        }
                    }
                    break;

                case 15: // ShapeType::Structure
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $this->renderStruct(
                            $valueSchema,
                            $value
                        );
                    }
                    break;

                case 16: // ShapeType::Union
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $this->renderUnion(
                            $valueSchema,
                            $value
                        );
                    }
                    break;

                case 13: // ShapeType::List_
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $this->renderListInline(
                            $valueSchema,
                            $value
                        );
                    }
                    break;

                case 14: // ShapeType::Map
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $this->renderMapInline(
                            $valueSchema,
                            $value
                        );
                    }
                    break;

                case 19: // ShapeType::Document
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $value instanceof DocumentInterface
                            ? $this->renderDocument($value)
                            : $value;
                    }
                    break;

                default:
                    foreach ($map as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $out[$stringKey] = $value;
                    }
            }
        }
        // Force object encoding for empty maps so they emit `{}` rather
        // than `[]`. Non-empty maps serialize correctly because PHP's
        // json_encode treats string-keyed arrays as objects.
        if ($out === []) {
            $this->emit(new \stdClass(), $idx);
            return;
        }
        $this->emit($out, $idx);
    }


    /**
     * Serialize a structure member into the current JSON frame.
     *
     * @return void
     */
    public function serializeStruct(
        Schema $schema,
        SerializableStructInterface $struct,
        int $idx
    ): void
    {
        // Thin wrapper around the imperative pair. The struct's `schema()`
        // takes precedence over the passed-in `$schema` so a filtered/wrapper
        // schema (e.g. the HTTP binding body schema for a different shape)
        // doesn't override the struct's actual member extension data.
        $this->beginStruct($struct::schema(), $idx);
        $struct->serializeMembers($this);
        $this->endStruct();
    }

    /**
     * Begin a structure frame for indexed member writes.
     *
     * @return void
     */
    public function beginStruct(Schema $schema, int $idx): void
    {
        // Local static cache of the codec's `SchemaExtensionKey`.
        // `JsonSchemaExtensionProvider::key()` is a singleton behind a
        // static-property `??=` — cheap, but firing it per struct-open on
        // large payloads shows up in the profile. Binding to a method-local
        // `static` collapses that to one method call per PHP process; every
        // subsequent invocation on this frame is a plain null-coalesce on
        // an already-populated local. Same idiom lives on
        // `renderStruct`, `renderUnion`, and the mirror sites in
        // `JsonDeserializer`.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);

        // Top-level frame: capture the transitive bignum flag so flush()
        // can short-circuit the BigNumberToken scan when no descendant
        // shape can produce one. Detect "top-level" via the empty frame
        // stack — nested begins push onto a non-empty stack.
        if ($this->frameStack === []) {
            $this->rootHasBigNumber = $info?->hasBigNumber ?? false;
        }

        // Snapshot the parent frame by value so `current` can be re-bound by
        // reference to a fresh frame. Both the parent frame and the open
        // child frame coexist on the stack until endStruct reattaches them.
        $this->frameStack[] = [
            $this->current,
            $this->wireNames,
            $this->timestampFormats,
            $idx,
        ];

        $newFrame = [];
        $this->current = &$newFrame;
        // Wire-name lookup pick: honoring `@jsonName` (restJson1) uses
        // `wireNameByMemberIndex`; ignoring it (awsJson1_x) uses
        // `rawWireNameByMemberIndex`. Chosen once per struct-open so
        // every subsequent `emit()` for members of this frame stays a
        // single array offset.
        $this->wireNames = $this->honorJsonName
            ? $info?->wireNameByMemberIndex
            : $info?->rawWireNameByMemberIndex;
        $this->timestampFormats = $info?->timestampFormats ?? [];
    }

    /**
     * End the current structure frame.
     *
     * @return void
     */
    public function endStruct(): void
    {
        $finishedFrame = $this->current;

        [$parentFrame, $parentWireNames, $parentTimestampFormats, $idx]
            = \array_pop($this->frameStack);

        // Force PHP to drop the by-reference binding before reassigning
        // `current` to the parent frame. Without unset, the parent frame's
        // members would inherit the just-released reference.
        unset($this->current);

        $this->wireNames = $parentWireNames;
        $this->timestampFormats = $parentTimestampFormats;

        if ($parentWireNames === null) {
            // Top-level frame closed: the finished frame is the root of the
            // JSON document. The empty-struct case must emit an object
            // (`{}`), not the JSON-encoded empty array (`[]`), so coerce
            // empty PHP arrays to `stdClass` here.
            $this->root = $finishedFrame === []
                ? new \stdClass()
                : $finishedFrame;
            $this->current = null;
            return;
        }

        // Nested: write the finished frame into the parent at `$idx`'s wire
        // name. Empty structs nest as `{}` for the same reason as above.
        $this->current = &$parentFrame;
        $key = $parentWireNames[$idx];
        $this->current[$key] = $finishedFrame === []
            ? new \stdClass()
            : $finishedFrame;
    }

    /**
     * Serialize a union member into the current JSON frame.
     *
     * @return void
     */
    public function serializeUnion(Schema $schema, mixed $union, int $idx): void
    {
        // Unions render as JSON objects with exactly one key: the active
        // variant's wire name. `renderUnion` already builds that single-key
        // array (honoring `@jsonName` via the JSON schema extension and
        // dispatching through `renderItem` for nested struct/list/map
        // variants), so emit its result directly. This avoids the frame
        // push/pop the previous `beginStruct`/`endStruct` path required.
        /** @var UnionInterface $union */
        $this->emit($this->renderUnion($union::schema(), $union), $idx);
    }

    /**
     * Serialize a document member into the current JSON frame.
     *
     * @return void
     */
    public function serializeDocument(
        Schema $schema,
        ?DocumentInterface $value,
        int $idx
    ): void {
        if ($value === null) {
            $this->emit(null, $idx);
            return;
        }
        // Each AbstractDocument subclass implements `serialize($s, $idx)` to
        // dispatch into the matching serializeXxx() method on this codec,
        // so document members ride the same write paths as their underlying
        // data-model type.
        $value->serialize($this, $idx);
    }

    /**
     * Create a JSON serializer.
     */
    public function __construct(
        /**
         * Whether to honor `smithy.api#jsonName` when picking the wire
         * name for each emitted member. `true` matches `restJson1`;
         * `false` (used by `awsJson1_x`) routes emits at the extension's
         * `rawWireNameByMemberIndex` array instead. Consulted only at
         * struct-open time (`beginStruct` / `renderStruct`), so the
         * emit-per-member fast path stays a single array offset.
         */
        private readonly bool $honorJsonName = true
    ) {}

    /**
     * Reset all serialization state so this instance can be reused by
     * {@see JsonCodec::createSerializer()} for the next operation.
     *
     * Every field written during a serialize/flush cycle is set back to
     * its initial value here. The codec pools one serializer per instance
     * and calls `reset()` before returning it — mirrors the smithy-java
     * `StripedPool` reuse pattern (single-slot version, since PHP request
     * handlers are single-threaded and one serialize call runs to
     * completion before the next begins).
     *
     * @internal Called by {@see JsonCodec} only.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->root = null;
        // `unset` before nulling to force PHP to release the by-reference
        // binding on `$current` that `endStruct` may have re-established.
        // Nulling in place while a by-ref still points at the last frame
        // would carry the previous request's data forward.
        unset($this->current);
        $this->current = null;
        $this->wireNames = null;
        $this->timestampFormats = null;
        $this->frameStack = [];
        $this->rootHasBigNumber = false;
        $this->blobEncodeCache = [];
    }

    /**
     * Final JSON body bytes. Called by the protocol or HTTP binding layer
     * after the top-level struct's `serializeMembers()` returns.
     */
    public function flush(): string
    {
        $encoded = \json_encode(
            $this->root,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new \RuntimeException(
                'JSON serialization failed: ' . \json_last_error_msg()
            );
        }
        // Replace BigNumberToken placeholders (rendered by json_encode as a
        // wrapping object) with their raw digit strings so the JSON number
        // token sits unquoted. The placeholder shape is intentionally
        // distinctive to make this substitution lossless.
        //
        // Gated on the extension-precomputed `rootHasBigNumber` flag: most
        // AWS services have no `BigInteger`/`BigDecimal`/`Document` shapes
        // in their schema graph, so this entire block is skipped (zero
        // scan, zero regex compile) for the common case. The flag is set
        // by `beginStruct` from the top-level schema's `JsonSchemaExtension`.
        if ($this->rootHasBigNumber) {
            $pattern = '/"'
                . \preg_quote(BigNumberToken::ENCODED_PREFIX, '/')
                . '(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)"/';
            $encoded = \preg_replace_callback(
                $pattern,
                static fn(array $m): string => $m[1],
                $encoded
            );
            if ($encoded === null) {
                throw new \RuntimeException(
                    'JSON serialization failed while unwrapping big numbers.'
                );
            }
        }
        // Reset internal state so the same JsonSerializer instance can be
        // reused for a subsequent operation if the protocol layer chooses.
        $this->root = null;
        $this->rootHasBigNumber = false;
        $this->blobEncodeCache = [];
        return $encoded;
    }

    /**
     * Write `$value` into the active frame at the wire name for `$idx`. If
     * no frame is active (top-level), assign to `$this->root` directly.
     *
     * `$wireNames` and `$current` move in lockstep with `beginStruct` /
     * `endStruct`, so checking just one is enough. The direct
     * `$wireNames[$idx]` access lets PHP raise its own undefined-index
     * notice when codegen ships a memberIndex that the extension didn't
     * register — fast path stays branchless.
     */
    private function emit(mixed $value, int $idx): void
    {
        if ($this->wireNames !== null) {
            $this->current[$this->wireNames[$idx]] = $value;
            return;
        }
        $this->root = $value;
    }

    /**
     * Render a single list/map item to its JSON-shaped value. Mirrors the
     * dispatch in `serializeXxx` methods, but for cases where the codec has
     * a raw PHP value in hand rather than a typed serialize call.
     *
     * `DocumentInterface` values are unwrapped first regardless of the
     * declared item schema — document collections (which fall back to
     * prelude schemas without `listMember`/`mapValue` set) end up with
     * `$memberSchema === null`, and even when a richer schema is present
     * an item that's a document needs its own dispatch chain to peel off
     * the wrapper before encoding.
     *
     * EnumInterface values are unwrapped to their underlying value (string
     * or int) so that lists/maps of enums render as wire-shaped primitives
     * instead of `{"value":"Foo"}`.
     */
    private function renderItem(?Schema $memberSchema, mixed $value): mixed
    {
        if ($value instanceof DocumentInterface) {
            return $this->renderDocument($value);
        }
        if ($value instanceof \Smithy\Core\Shape\EnumInterface) {
            return $value->getValue();
        }
        if ($memberSchema === null) {
            return $value;
        }
        // Literal-int dispatch matches the project-wide pattern (see
        // Schema::$type docblock). The Document arm intentionally falls
        // through to `default` because the DocumentInterface early-return
        // above already covers every document value; any non-document
        // value tagged Document by codegen is a bug and should fall
        // through unchanged so the wire encoder surfaces it.
        return match ($memberSchema->type) {
            15 => $this->renderStruct($memberSchema, $value),       // Structure
            16 => $this->renderUnion($memberSchema, $value),        // Union
            13 => $this->renderListInline($memberSchema, $value),   // List_
            14 => $this->renderMapInline($memberSchema, $value),    // Map
            11 => isset($this->blobEncodeCache[$value])
                ? $this->blobEncodeCache[$value]
                : ($this->blobEncodeCache[$value] = \base64_encode($value)),
            // Blob
            12 => $this->renderTimestamp($memberSchema, $value),    // Timestamp
            // BigInteger, BigDecimal
            8 => $this->wrapBigIntegerToken($value),                // BigInteger
            9 => $this->wrapBigDecimalToken($value),                // BigDecimal
            6, 7 => $this->renderFloat((float) $value), // Float_, Double
            default => $value
        };
    }

    private function renderStruct(Schema $memberSchema, mixed $value): array|\stdClass
    {
        // See `beginStruct` for the rationale; same local-static pattern.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var SerializableStructInterface $value */
        $structSchema = $value::schema();
        /** @var JsonSchemaExtension $info */
        $info = $structSchema->getExtension($extKey);

        // Capture the parent's aliased `$this->current` by reference so
        // we can re-alias to the same target after emitting our inline
        // struct. A by-value snapshot would decouple `$this->current`
        // from the parent's live frame, and any subsequent `emit()`
        // (e.g. the enclosing `serializeList`'s post-loop
        // `emit($out, $idx)`) would land in a detached copy — the
        // recursive-struct-in-list "member serializes as `[]`" failure
        // mode. See {@see beginStruct} for the frame-stack mechanism
        // this mirrors on the imperative path.
        $prevCurrent = &$this->current;
        $prevWireNames = $this->wireNames;
        $prevTsFormats = $this->timestampFormats;

        $frame = [];
        unset($this->current);
        $this->current = &$frame;
        // Same wire-name pick as `beginStruct`; consulted once per
        // struct-open so per-member emit stays branchless.
        $this->wireNames = $this->honorJsonName
            ? $info->wireNameByMemberIndex
            : $info->rawWireNameByMemberIndex;
        $this->timestampFormats = $info->timestampFormats;

        $value->serializeMembers($this);

        // Capture the finished frame BEFORE unwinding the reference chain.
        // A nested imperative `beginStruct`/`endStruct` pair inside the
        // just-ran `serializeMembers` snapshots `$this->current` onto its
        // `frameStack`, rebinds `$this->current` to a fresh new-frame local
        // for the inner struct, and on `endStruct` rebinds `$this->current`
        // again to a `$parentFrame` local popped off the stack. Writes for
        // the inner struct's siblings on the outer struct thus land in
        // `endStruct`'s popped local — not in our local `$frame` variable.
        // Reading `$this->current` here captures whichever zval currently
        // holds the emitted members. Empty structs coerce to `stdClass` to
        // preserve `{}` vs `[]` on the wire, matching the imperative
        // `endStruct` policy.
        $result = $this->current;

        unset($this->current);
        $this->current = &$prevCurrent;
        $this->wireNames = $prevWireNames;
        $this->timestampFormats = $prevTsFormats;

        return $result === [] ? new \stdClass() : $result;
    }

    private function renderUnion(Schema $memberSchema, mixed $value): array
    {
        // See `beginStruct` for the rationale; same local-static pattern.
        // This is the hottest of the key-cache sites on the serialize
        // side because every element of a map/list of unions passes
        // through here on the way to `renderItem`.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();

        // Raw-array fast path.
        //
        // Every map value inside a `Map<K, MyUnion>` and every list item
        // inside a `List<MyUnion>` arrives here as the on-wire
        // associative array (e.g. `['S' => 'hello']`) — the parent
        // struct's array hydration path doesn't recursively hydrate map
        // values / list items into `MyUnion` variant instances.
        // Round-tripping through union hydration just to read `tag()`
        // and `value()` back off the resulting variant object allocates a
        // throwaway wrapper per element, which dominated the wall-clock
        // on `PutItemRequest_BinaryData_L` / `PutItemRequest_MixedItem_L`
        // pre-fix (see the flame graph under
        // `renderUnion → AttributeValue::fromArray`). This branch reads
        // the same information — variant tag, variant value — directly
        // off the raw array and dispatches through the schema without
        // allocating a wrapper.
        //
        // Callers who pass hydrated `UnionInterface` variants (via
        // `Foo::s("x")` factories or via a codec-driven
        // deserialize → serialize round-trip) still take the object
        // path below, so hydrated-input performance is unchanged.
        if (\is_array($value)) {
            // Resolve the union schema through the member's targetClass
            // when we have one — this is a single class-name-string
            // dereference into the memoized `Schemas::foo()` factory,
            // cheaper than `resolveTarget()`'s trait-map walk. Members
            // without `targetClass` (extremely rare — synthetic
            // runtime-built schemas) fall back to `resolveTarget()` so
            // the payload isn't dropped.
            $target = $memberSchema->targetClass;
            $unionSchema = $target !== null
                ? $target::schema()
                : $memberSchema->resolveTarget();
            /** @var JsonSchemaExtension|null $info */
            $info = $unionSchema->getExtension($extKey);
            $unionEncodeByMemberName = $info === null
                ? []
                : ($this->honorJsonName
                    ? $info->unionEncodeByMemberName
                    : $info->rawUnionEncodeByMemberName);

            // Find the (typically only) non-null variant. Callers are
            // allowed to send `variantName: null` for absent variants
            // (matches the mirror semantics in
            // {@see JsonDeserializer::readUnion} and the AllowNulls
            // compliance case); skip nulls and take the first live
            // entry as the active variant.
            foreach ($value as $tag => $variantValue) {
                if ($variantValue === null) {
                    continue;
                }
                $tag = (string) $tag;
                $entry = $unionEncodeByMemberName[$tag] ?? null;
                if ($entry === null) {
                    return [$tag => $variantValue];
                }

                switch ($entry[1]) {
                    case 10:
                        return [$entry[0] => (string) $variantValue];

                    case 17:
                    case 18:
                        return [
                            $entry[0] =>
                                $variantValue
                                    instanceof \Smithy\Core\Shape\EnumInterface
                                ? $variantValue->getValue()
                                : (
                                    $entry[1] === 18
                                        ? (int) $variantValue
                                        : (string) $variantValue
                                )
                        ];

                    case 1:
                        return [$entry[0] => (bool) $variantValue];

                    case 4:
                    case 5:
                    case 3:
                    case 2:
                        return [$entry[0] => (int) $variantValue];

                    case 6:
                    case 7:
                        return [$entry[0] => match (
                            $str = (string) (float) $variantValue
                        ) {
                            'NAN' => 'NaN',
                            'INF' => 'Infinity',
                            '-INF' => '-Infinity',
                            default => (float) $variantValue
                        }];

                    case 11:
                        $cache = &$this->blobEncodeCache;
                        if (isset($cache[$variantValue])) {
                            return [$entry[0] => $cache[$variantValue]];
                        }
                        $encoded = \base64_encode($variantValue);
                        $cache[$variantValue] = $encoded;
                        return [$entry[0] => $encoded];

                    case 12:
                        $resolved = $entry[2] ?? TimestampFormat::EpochSeconds;
                        $epoch = $variantValue;
                        if ($variantValue instanceof \DateTimeInterface) {
                            $epoch = (float) $variantValue->format('U.u');
                            if ($epoch == (int) $epoch) {
                                $epoch = (int) $epoch;
                            }
                        }
                        return [$entry[0] =>
                            $resolved === TimestampFormat::EpochSeconds
                                ? $epoch
                                : $resolved->format($epoch)];

                    case 8:
                        return [$entry[0] =>
                            $this->wrapBigIntegerToken($variantValue)];

                    case 9:
                        return [$entry[0] =>
                            $this->wrapBigDecimalToken($variantValue)];

                    case 15:
                        return [$entry[0] =>
                            $this->renderStruct($entry[2], $variantValue)];

                    case 16:
                        return [$entry[0] =>
                            $this->renderUnion($entry[2], $variantValue)];

                    case 13:
                        return [$entry[0] =>
                            $this->renderListInline($entry[2], $variantValue)];

                    case 14:
                        return [$entry[0] =>
                            $this->renderMapInline($entry[2], $variantValue)];

                    case 19:
                        return [$entry[0] =>
                            $variantValue instanceof DocumentInterface
                                ? $this->renderDocument($variantValue)
                                : $variantValue];

                    default:
                        return [$entry[0] => $variantValue];
                }
            }
            // Every entry was null or the array was empty. Emit an
            // empty JSON object — matches the shape struct's empty
            // frame policy in `endStruct` (empty PHP array → `{}`).
            return [];
        }

        /** @var UnionInterface $value */
        $unionSchema = $value::schema();
        /** @var JsonSchemaExtension $info */
        $info = $unionSchema->getExtension($extKey);

        $tag = $value->tag();
        $entry = ($this->honorJsonName
            ? $info->unionEncodeByMemberName
            : $info->rawUnionEncodeByMemberName)[$tag] ?? null;
        $variantValue = $value->value();

        if ($entry === null) {
            return [$tag => $variantValue];
        }

        switch ($entry[1]) {
            case 10:
                return [$entry[0] => (string) $variantValue];

            case 17:
            case 18:
                return [$entry[0] =>
                    $variantValue instanceof \Smithy\Core\Shape\EnumInterface
                        ? $variantValue->getValue()
                        : (
                            $entry[1] === 18
                                ? (int) $variantValue
                                : (string) $variantValue
                        )];

            case 1:
                return [$entry[0] => (bool) $variantValue];

            case 4:
            case 5:
            case 3:
            case 2:
                return [$entry[0] => (int) $variantValue];

            case 6:
            case 7:
                return [$entry[0] => match (
                    $str = (string) (float) $variantValue
                ) {
                    'NAN' => 'NaN',
                    'INF' => 'Infinity',
                    '-INF' => '-Infinity',
                    default => (float) $variantValue
                }];

            case 11:
                $cache = &$this->blobEncodeCache;
                if (isset($cache[$variantValue])) {
                    return [$entry[0] => $cache[$variantValue]];
                }
                $encoded = \base64_encode($variantValue);
                $cache[$variantValue] = $encoded;
                return [$entry[0] => $encoded];

            case 12:
                $resolved = $entry[2] ?? TimestampFormat::EpochSeconds;
                $epoch = $variantValue;
                if ($variantValue instanceof \DateTimeInterface) {
                    $epoch = (float) $variantValue->format('U.u');
                    if ($epoch == (int) $epoch) {
                        $epoch = (int) $epoch;
                    }
                }
                return [$entry[0] =>
                    $resolved === TimestampFormat::EpochSeconds
                        ? $epoch
                        : $resolved->format($epoch)];

            case 8:
                return [$entry[0] =>
                    $this->wrapBigIntegerToken($variantValue)];

            case 9:
                return [$entry[0] =>
                    $this->wrapBigDecimalToken($variantValue)];

            case 15:
                return [$entry[0] =>
                    $this->renderStruct($entry[2], $variantValue)];

            case 16:
                return [$entry[0] =>
                    $this->renderUnion($entry[2], $variantValue)];

            case 13:
                return [$entry[0] =>
                    $this->renderListInline($entry[2], $variantValue)];

            case 14:
                return [$entry[0] =>
                    $this->renderMapInline($entry[2], $variantValue)];

            case 19:
                return [$entry[0] =>
                    $variantValue instanceof DocumentInterface
                        ? $this->renderDocument($variantValue)
                        : $variantValue];

            default:
                return [$entry[0] => $variantValue];
        }
    }


    private function renderListInline(Schema $memberSchema, array $items): array
    {
        // Same trait-lift-to-extension pattern as `serializeList`.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $itemSchema = $memberSchema->listMember;
        /** @var JsonSchemaExtension|null $info */
        $info = $memberSchema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;
        $type = $info?->listMemberType ?? $itemSchema?->type;

        $out = [];
        if ($itemSchema === null || $type === null) {
            foreach ($items as $item) {
                if ($item === null) {
                    if ($sparse) {
                        $out[] = null;
                    }
                    continue;
                }
                $out[] = $item;
            }
            return $out;
        }

        switch ($type) {
            case 10: // ShapeType::String_
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = (string) $item;
                }
                break;

            case 17: // ShapeType::Enum
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item instanceof \Smithy\Core\Shape\EnumInterface
                        ? $item->getValue()
                        : (string) $item;
                }
                break;

            case 1: // ShapeType::Boolean
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = (bool) $item;
                }
                break;

            case 4: // ShapeType::Integer
            case 5: // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = (int) $item;
                }
                break;

            case 18: // ShapeType::IntEnum
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item instanceof \Smithy\Core\Shape\EnumInterface
                        ? $item->getValue()
                        : (int) $item;
                }
                break;

            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = match ($str = (string) (float) $item) {
                        'NAN' => 'NaN',
                        'INF' => 'Infinity',
                        '-INF' => '-Infinity',
                        default => (float) $item,
                    };
                }
                break;

            case 11: // ShapeType::Blob
                $cache = &$this->blobEncodeCache;
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    if (isset($cache[$item])) {
                        $out[] = $cache[$item];
                        continue;
                    }
                    $encoded = \base64_encode($item);
                    $cache[$item] = $encoded;
                    $out[] = $encoded;
                }
                break;

            case 8: // ShapeType::BigInteger
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->wrapBigIntegerToken($item);
                }
                break;

            case 9: // ShapeType::BigDecimal
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->wrapBigDecimalToken($item);
                }
                break;

            case 12: // ShapeType::Timestamp
                $resolved = $info?->listMemberTimestampFormat
                    ?? TimestampFormat::EpochSeconds;
                if ($resolved === TimestampFormat::EpochSeconds) {
                    foreach ($items as $item) {
                        if ($item === null) {
                            if ($sparse) {
                                $out[] = null;
                            }
                            continue;
                        }
                        $epoch = $item;
                        if ($item instanceof \DateTimeInterface) {
                            $epoch = (float) $item->format('U.u');
                            if ($epoch == (int) $epoch) {
                                $epoch = (int) $epoch;
                            }
                        }
                        $out[] = $epoch;
                    }
                } else {
                    foreach ($items as $item) {
                        if ($item === null) {
                            if ($sparse) {
                                $out[] = null;
                            }
                            continue;
                        }
                        $epoch = $item;
                        if ($item instanceof \DateTimeInterface) {
                            $epoch = (float) $item->format('U.u');
                            if ($epoch == (int) $epoch) {
                                $epoch = (int) $epoch;
                            }
                        }
                        $out[] = $resolved->format($epoch);
                    }
                }
                break;

            case 15: // ShapeType::Structure
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderStruct($itemSchema, $item);
                }
                break;

            case 16: // ShapeType::Union
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderUnion($itemSchema, $item);
                }
                break;

            case 13: // ShapeType::List_
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderListInline($itemSchema, $item);
                }
                break;

            case 14: // ShapeType::Map
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $this->renderMapInline($itemSchema, $item);
                }
                break;

            case 19: // ShapeType::Document
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item instanceof DocumentInterface
                        ? $this->renderDocument($item)
                        : $item;
                }
                break;

            default:
                foreach ($items as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $out[] = null;
                        }
                        continue;
                    }
                    $out[] = $item;
                }
        }
        return $out;
    }

    private function renderMapInline(Schema $memberSchema, array $entries): array|\stdClass
    {
        // Same trait-lift-to-extension pattern as `serializeMap`.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $valueSchema = $memberSchema->mapValue;
        /** @var JsonSchemaExtension|null $info */
        $info = $memberSchema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;
        $out = [];

        if ($valueSchema === null) {
            foreach ($entries as $key => $value) {
                $stringKey = (string) $key;
                if ($value === null) {
                    if ($sparse) {
                        $out[$stringKey] = null;
                    }
                    continue;
                }
                $out[$stringKey] = $value;
            }
            return $out === [] ? new \stdClass() : $out;
        }

        $type = $info?->mapValueType ?? $valueSchema->type;
        switch ($type) {
            case 10: // ShapeType::String_
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = (string) $value;
                }
                break;

            case 17: // ShapeType::Enum
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] =
                        $value instanceof \Smithy\Core\Shape\EnumInterface
                            ? $value->getValue()
                            : (string) $value;
                }
                break;

            case 1: // ShapeType::Boolean
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = (bool) $value;
                }
                break;

            case 4: // ShapeType::Integer
            case 5: // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = (int) $value;
                }
                break;

            case 18: // ShapeType::IntEnum
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] =
                        $value instanceof \Smithy\Core\Shape\EnumInterface
                            ? $value->getValue()
                            : (int) $value;
                }
                break;

            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = match (
                        $str = (string) (float) $value
                    ) {
                        'NAN' => 'NaN',
                        'INF' => 'Infinity',
                        '-INF' => '-Infinity',
                        default => (float) $value
                    };
                }
                break;

            case 11: // ShapeType::Blob
                $cache = &$this->blobEncodeCache;
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    if (isset($cache[$value])) {
                        $out[$stringKey] = $cache[$value];
                        continue;
                    }
                    $encoded = \base64_encode($value);
                    $cache[$value] = $encoded;
                    $out[$stringKey] = $encoded;
                }
                break;

            case 8: // ShapeType::BigInteger
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $this->wrapBigIntegerToken($value);
                }
                break;

            case 9: // ShapeType::BigDecimal
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $this->wrapBigDecimalToken($value);
                }
                break;

            case 12: // ShapeType::Timestamp
                $resolved = $info?->mapValueTimestampFormat
                    ?? TimestampFormat::EpochSeconds;
                if ($resolved === TimestampFormat::EpochSeconds) {
                    foreach ($entries as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $epoch = $value;
                        if ($value instanceof \DateTimeInterface) {
                            $epoch = (float) $value->format('U.u');
                            if ($epoch == (int) $epoch) {
                                $epoch = (int) $epoch;
                            }
                        }
                        $out[$stringKey] = $epoch;
                    }
                } else {
                    foreach ($entries as $key => $value) {
                        $stringKey = (string) $key;
                        if ($value === null) {
                            if ($sparse) {
                                $out[$stringKey] = null;
                            }
                            continue;
                        }
                        $epoch = $value;
                        if ($value instanceof \DateTimeInterface) {
                            $epoch = (float) $value->format('U.u');
                            if ($epoch == (int) $epoch) {
                                $epoch = (int) $epoch;
                            }
                        }
                        $out[$stringKey] = $resolved->format($epoch);
                    }
                }
                break;

            case 15: // ShapeType::Structure
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $this->renderStruct(
                        $valueSchema,
                        $value
                    );
                }
                break;

            case 16: // ShapeType::Union
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $this->renderUnion(
                        $valueSchema,
                        $value
                    );
                }
                break;

            case 13: // ShapeType::List_
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $this->renderListInline(
                        $valueSchema,
                        $value
                    );
                }
                break;

            case 14: // ShapeType::Map
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $this->renderMapInline(
                        $valueSchema,
                        $value
                    );
                }
                break;

            case 19: // ShapeType::Document
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $value instanceof DocumentInterface
                        ? $this->renderDocument($value)
                        : $value;
                }
                break;

            default:
                foreach ($entries as $key => $value) {
                    $stringKey = (string) $key;
                    if ($value === null) {
                        if ($sparse) {
                            $out[$stringKey] = null;
                        }
                        continue;
                    }
                    $out[$stringKey] = $value;
                }
        }
        return $out === [] ? new \stdClass() : $out;
    }

    private function renderTimestamp(Schema $memberSchema, mixed $value): mixed
    {
        // Format was resolved once by the provider (default epoch-seconds
        // per the JSON default). The extension's `$memberTimestampFormat`
        // is populated by `JsonSchemaExtensionProvider::buildTimestampInfo`
        // and skips the per-render `getTrait` + `fromTraitString`
        // resolution chain — trait lookups have no place on the serde
        // hot path.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $memberSchema->getExtension($extKey);
        $resolved = $info?->memberTimestampFormat
            ?? TimestampFormat::EpochSeconds;

        $epoch = $value;

        if ($value instanceof \DateTimeInterface) {
            $epoch = (float) $value->format('U.u');
            if ($epoch == (int) $epoch) {
                $epoch = (int) $epoch;
            }
        }

        if ($resolved === TimestampFormat::EpochSeconds) {
            return $epoch;
        }
        return $resolved->format($epoch);
    }

    private function renderFloat(float $value): mixed
    {
        // Same sentinel-string strategy as serializeFloat / XmlSerializer.
        // See serializeFloat for the rationale.
        $s = match ($str = (string) $value) {
            'NAN'  => 'NaN',
            'INF'  => 'Infinity',
            '-INF' => '-Infinity',
            default => null,
        };
        return $s ?? $value;
    }

    private function renderDocument(DocumentInterface $value): mixed
    {
        // Drive a temporary sub-serializer so the document subclass can
        // dispatch through its own `serialize($s, $idx)` chain. This keeps
        // the document inside list/map item context isolated from the
        // outer frame.
        $sub = $this->documentSerializer ??= new self($this->honorJsonName);
        $sub->reset();
        $value->serialize($sub, 0);
        if ($sub->rootHasBigNumber) {
            $this->rootHasBigNumber = true;
        }
        $encoded = $sub->root;
        return $encoded;
    }

    /**
     * Wrap a big-integer value for raw JSON-number emission.
     */
    private function wrapBigIntegerToken(mixed $value): BigNumberToken
    {
        $digits = $value instanceof BigInteger
            ? $value->value
            : (string) $value;

        return $this->wrapBigNumberToken(
            self::normalizeBigIntegerDigits($digits)
        );
    }

    /**
     * Wrap a big-decimal value for raw JSON-number emission.
     */
    private function wrapBigDecimalToken(mixed $value): BigNumberToken
    {
        $digits = $value instanceof BigDecimal
            ? $value->value
            : (string) $value;

        return $this->wrapBigNumberToken($digits);
    }

    /**
     * Mark the current payload as containing a big number and create its
     * replacement token.
     */
    private function wrapBigNumberToken(string $digits): BigNumberToken
    {
        $this->rootHasBigNumber = true;
        return new BigNumberToken($digits);
    }

    /**
     * Normalize a BigInteger digit string to a valid JSON integer token.
     */
    private static function normalizeBigIntegerDigits(string $digits): string
    {
        $negative = $digits !== '' && $digits[0] === '-';
        $unsigned = $negative ? \substr($digits, 1) : $digits;
        $unsigned = \ltrim($unsigned, '0');

        if ($unsigned === '') {
            return '0';
        }

        return $negative ? '-' . $unsigned : $unsigned;
    }
}
