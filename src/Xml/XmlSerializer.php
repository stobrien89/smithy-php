<?php

declare(strict_types=1);

namespace Smithy\Xml;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\DocumentInterface;
use Smithy\Core\Shape\EnumInterface;
use Smithy\Core\Shape\UnionInterface;

/**
 * Serialize Smithy shapes with a C-extension XML writer.
 *
 * Stashes struct context on entry so scalar write methods can resolve wire
 * names and attributes through local arrays instead of repeated extension
 * lookups.
 *
 * @internal Instantiated only by {@see XmlCodec}. External callers should
 *           depend on {@see ShapeSerializerInterface}. The concrete writer
 *           choice is a private detail of the codec.
 */
final class XmlSerializer implements ShapeSerializerInterface
{
    /** @var array<int, string> memberIndex -> XML wire name */
    private array $wireNames = [];

    /** @var array<int, int> memberIndex -> XmlSchemaExtension::COMPONENT_* */
    private array $memberComponentTypes = [];

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $nsAttrs = [];

    /** @var array<int, TimestampFormat> memberIndex -> TimestampFormat enum */
    private array $tsFormats = [];

    /**
     * Serialize a boolean member.
     *
     * @return void
     */
    public function serializeBoolean(
        Schema $schema,
        bool $value,
        int $idx
    ): void
    {
        $str = $value ? 'true' : 'false';
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $str);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $str);
        }
    }

    /**
     * Serialize an integer-like member.
     *
     * @return void
     */
    public function serializeInteger(
        Schema $schema,
        int $value,
        int $idx
    ): void
    {
        $str = (string) $value;
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $str);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $str);
        }
    }

    /**
     * Serialize a floating-point member.
     *
     * @return void
     */
    public function serializeFloat(Schema $schema, float $value, int $idx): void
    {
        $str = match ($s = (string) $value) {
            'NAN' => 'NaN',
            'INF' => 'Infinity',
            '-INF' => '-Infinity',
            default => $s,
        };

        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $str);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $str);
        }
    }

    /**
     * Serialize a big integer member.
     *
     * @return void
     */
    public function serializeBigInteger(
        Schema $schema,
        BigInteger $value,
        int $idx
    ): void
    {
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute(
                $this->wireNames[$idx],
                $value->value
            );
        } else {
            $this->writer->writeElement(
                $this->wireNames[$idx],
                $value->value
            );
        }
    }

    /**
     * Serialize a big decimal member.
     *
     * @return void
     */
    public function serializeBigDecimal(
        Schema $schema,
        BigDecimal $value,
        int $idx
    ): void
    {
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute(
                $this->wireNames[$idx],
                $value->value
            );
        } else {
            $this->writer->writeElement(
                $this->wireNames[$idx],
                $value->value
            );
        }
    }

    /**
     * Serialize a string-like member.
     *
     * @return void
     */
    public function serializeString(Schema $schema, string $value, int $idx): void
    {
        // String members are the only scalar path that can carry a member namespace.
        $ct = $this->memberComponentTypes[$idx];
        if ($ct === 0) {
            $this->writer->writeElement($this->wireNames[$idx], $value);
            return;
        }
        if ($ct === 1) {
            $this->writer->writeAttribute($this->wireNames[$idx], $value);
            return;
        }
        $ns = $this->nsAttrs[$idx];
        $this->writer->startElement($this->wireNames[$idx]);
        $this->writer->writeAttribute($ns[0], $ns[1]);
        $this->writer->text($value);
        $this->writer->endElement();
    }

    /**
     * Serialize a blob member.
     *
     * @return void
     */
    public function serializeBlob(Schema $schema, string $value, int $idx): void
    {
        $encoded = \base64_encode($value);
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $encoded);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $encoded);
        }
    }

    /**
     * Serialize a timestamp member.
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
        $formatted = ($format ?? TimestampFormat::DateTime)->format($value);

        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $formatted);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $formatted);
        }
    }

    /**
     * Serialize a list member.
     *
     * @param list<mixed> $list
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
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $listMember = $info->listMemberSchema;

        $xmlName = $info->xmlName ?: $schema->memberName;
        $itemName = $info->flattened ? $xmlName : $info->listMemberName;

        if (!$info->flattened) {
            $this->writer->startElement($xmlName);
            $this->applyXmlNamespace($info);
        }

        $memberNs = $info->listMemberNamespace;
        $nsUri = $memberNs['uri'] ?? null;
        $nsPrefix = $memberNs['prefix'] ?? null;

        switch ($memberType->value) {
            case 10:  // ShapeType::String_
            case 17:  // ShapeType::Enum
                $this->writeStringItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case 4:  // ShapeType::Integer

            case 5:  // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                $this->writeIntItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case 1: // ShapeType::Boolean
                $this->writeBoolItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                $this->writeFloatItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case 12: // ShapeType::Timestamp
                $timestampFormat = $info->listMemberTimestampFormat
                    ?? $this->tsFormats[$idx]
                    ?? TimestampFormat::DateTime;
                $this->writeTimestampItems(
                    $list,
                    $itemName,
                    $timestampFormat,
                    $nsUri,
                    $nsPrefix
                );
                break;
            case 11: // ShapeType::Blob
                $this->writeBlobItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case 15: // ShapeType::Structure
                $this->writeStructItems($list, $itemName, $listMember);
                break;
            case 13: // ShapeType::List_
                $this->writeListItems($list, $itemName, $listMember);
                break;
            case 14: // ShapeType::Map
                $this->writeMapItems($list, $itemName, $listMember);
                break;
            default:
                $this->writeGenericItems(
                    $list,
                    $itemName,
                    $listMember,
                    $nsUri,
                    $nsPrefix
                );
                break;
        }

        if (!$info->flattened) {
            $this->writer->endElement();
        }
    }

    /**
     * Serialize a map member.
     *
     * @param array<array-key, mixed> $map
     *
     * @return void
     */
    public function serializeMap(Schema $schema, array $map, int $idx): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $mapKey = $schema->getMapKey();
        $mapValue = $schema->getMapValue();

        $keyName = $info->mapKeyName;
        $valueName = $info->mapValueName;
        $entryName = $info->mapEntryName;

        if (!$info->flattened) {
            $this->writer->startElement($info->xmlName ?: $schema->memberName);
            $this->applyXmlNamespace($info);
        }

        $valueType = $mapValue?->type;
        $keyNs = $mapKey?->getExtension($extKey)?->xmlNamespace;
        $keyNsUri = $keyNs['uri'] ?? null;
        $keyNsPrefix = $keyNs['prefix'] ?? null;
        $valueNs = $mapValue?->getExtension($extKey)?->xmlNamespace;
        $valueNsUri = $valueNs['uri'] ?? null;
        $valueNsPrefix = $valueNs['prefix'] ?? null;

        foreach ($map as $key => $value) {
            $this->writer->startElement($entryName);

            if ($keyNsUri !== null) {
                $this->writer->startElement($keyName);
                $this->writer->writeAttribute(
                    $keyNsPrefix ? "xmlns:{$keyNsPrefix}" : 'xmlns',
                    $keyNsUri
                );
                $this->writer->text((string) $key);
                $this->writer->endElement();
            } else {
                $this->writer->writeElement($keyName, (string) $key);
            }

            $this->writer->startElement($valueName);
            if ($valueNsUri !== null) {
                $this->writer->writeAttribute(
                    $valueNsPrefix ? "xmlns:{$valueNsPrefix}" : 'xmlns',
                    $valueNsUri
                );
            }
            if ($mapValue !== null && $valueType !== null) {
                $this->writeScalarOrNested($mapValue, $value, $valueType);
            } else {
                $this->writer->text((string) $value);
            }
            $this->writer->endElement();
            $this->writer->endElement();
        }

        if (!$info->flattened) {
            $this->writer->endElement();
        }
    }

    /**
     * Serialize a structure member.
     *
     * @return void
     */
    public function serializeStruct(
        Schema $schema,
        SerializableStructInterface $struct,
        int $idx
    ): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);

        $this->writer->startElement($info->xmlName);

        $ns = $info->xmlNamespace;
        if ($ns) {
            $prefix = $ns['prefix'] ?? null;
            if ($prefix) {
                $this->writer->writeAttribute("xmlns:{$prefix}", $ns['uri']);
            } else {
                $this->writer->writeAttribute('xmlns', $ns['uri']);
            }
        } elseif ($this->defaultNamespace && $schema->memberName) {
            $this->writer->writeAttribute('xmlns', $this->defaultNamespace);
        }

        $prevWireNames = $this->wireNames;
        $prevMemberComponentTypes = $this->memberComponentTypes;
        $prevNsAttrs = $this->nsAttrs;
        $prevTsFormats = $this->tsFormats;

        $structSchema = $struct::schema();
        /** @var XmlSchemaExtension $structInfo */
        $structInfo = $structSchema->getExtension($extKey);
        $this->wireNames = $structInfo->memberWireNames;
        $this->memberComponentTypes = $structInfo->memberComponentTypes;
        $this->nsAttrs = $structInfo->nsAttrs;
        $this->tsFormats = $structInfo->tsFormats;

        if ($schema === $structSchema || !$schema->getMemberCount()) {
            $struct->serializeMembers($this);
        } else {
            if ($info->memberWireNames !== []) {
                $this->wireNames = $info->memberWireNames;
                $this->memberComponentTypes = $info->memberComponentTypes;
                $this->nsAttrs = $info->nsAttrs;
                $this->tsFormats = $info->tsFormats;
            }

            // Attributes must be written before child elements.
            if ($info->hasAttributes) {
                foreach ($info->attributeMembers as $xmlName => $ms) {
                    $value = $struct->getMemberValue($ms);
                    if ($value === null) {
                        continue;
                    }

                    $this->writer->writeAttribute($xmlName, (string) $value);
                }
            }
            foreach ($info->elementMembers as $ms) {
                $value = $struct->getMemberValue($ms);
                if ($value === null) {
                    continue;
                }

                $this->writeMemberValue($ms, $value);
            }
        }

        $this->writer->endElement();

        $this->wireNames = $prevWireNames;
        $this->memberComponentTypes = $prevMemberComponentTypes;
        $this->nsAttrs = $prevNsAttrs;
        $this->tsFormats = $prevTsFormats;
    }

    /**
     * Begin a structure member.
     *
     * @return void
     */
    public function beginStruct(Schema $schema, int $idx): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $this->writer->startElement($info->xmlName);

        $ns = $info->xmlNamespace;
        if ($ns) {
            $prefix = $ns['prefix'] ?? null;
            if ($prefix) {
                $this->writer->writeAttribute("xmlns:{$prefix}", $ns['uri']);
            } else {
                $this->writer->writeAttribute('xmlns', $ns['uri']);
            }
        } elseif ($this->defaultNamespace && $schema->memberName) {
            $this->writer->writeAttribute('xmlns', $this->defaultNamespace);
        }

        $this->wireNames = $info->memberWireNames;
        $this->memberComponentTypes = $info->memberComponentTypes;
        $this->nsAttrs = $info->nsAttrs;
        $this->tsFormats = $info->tsFormats;
    }

    /**
     * End the current structure member.
     *
     * @return void
     */
    public function endStruct(): void
    {
        $this->writer->endElement();
    }

    /**
     * Serialize a union member.
     *
     * @return void
     */
    public function serializeUnion(Schema $schema, mixed $union, int $idx): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $this->writer->startElement($info->xmlName);

        $tag = $union->tag();
        $value = $union->value();

        $iterInfo = $info;
        if (
            $info->unionMembersByName === []
            && $union instanceof UnionInterface
        ) {
            $iterInfo = $union::schema()->getExtension($extKey);
        }
        $variantSchema = $iterInfo->unionMembersByName[$tag] ?? null;

        if ($variantSchema !== null) {
            $prevWireNames = $this->wireNames;
            $prevMemberComponentTypes = $this->memberComponentTypes;
            $prevNsAttrs = $this->nsAttrs;
            $prevTsFormats = $this->tsFormats;
            $this->wireNames = $iterInfo->memberWireNames;
            $this->memberComponentTypes = $iterInfo->memberComponentTypes;
            $this->nsAttrs = $iterInfo->nsAttrs;
            $this->tsFormats = $iterInfo->tsFormats;

            $this->writeMemberValue($variantSchema, $value);

            $this->wireNames = $prevWireNames;
            $this->memberComponentTypes = $prevMemberComponentTypes;
            $this->nsAttrs = $prevNsAttrs;
            $this->tsFormats = $prevTsFormats;
        } else {
            $this->writer->writeElement($tag, (string) $value);
        }

        $this->writer->endElement();
    }

    /**
     * Reject document member serialization.
     *
     * @return void
     *
     * @throws \LogicException When XML serialization reaches a document member.
     */
    public function serializeDocument(
        Schema $schema,
        ?DocumentInterface $value,
        int $idx
    ): void
    {
        // Smithy XML protocols have no wire representation for document members.
        throw new \LogicException(\sprintf(
            'XML protocols do not support document-typed members; '
            . 'member "%s" of shape "%s" cannot be serialized.',
            $schema->memberName ?? '(unnamed)',
            $schema->id
        ));
    }

    /**
     * Construct the serializer.
     *
     * @param \XMLWriter $writer Backing XML writer.
     * @param string|null $defaultNamespace Default namespace for unqualified structs.
     */
    public function __construct(
        private readonly \XMLWriter $writer,
        private readonly ?string $defaultNamespace = null
    ) {}

    /** Flush the XML buffer. */
    public function flush(): string
    {
        return $this->writer->outputMemory();
    }


    // =========================================================================
    // Typed list fast-path methods (no per-item dispatch or metadata lookup)
    // =========================================================================

    /**
     * Write string-like list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeStringItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writer->writeElement($itemName, (string) $item);
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text((string) $item);
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write integer-like list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeIntItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writer->writeElement($itemName, (string) $item);
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text((string) $item);
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write boolean list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeBoolItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writer->writeElement(
                    $itemName,
                    $item ? 'true' : 'false'
                );
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text($item ? 'true' : 'false');
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write floating-point list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeFloatItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $item = (string) $item;
                $this->writer->writeElement($itemName, match ($item) {
                    'NAN' => 'NaN',
                    'INF' => 'Infinity',
                    '-INF'=> '-Infinity',
                    default => $item,
                });
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $item = (string) $item;
                $this->writer->text(match ($item) {
                    'NAN' => 'NaN',
                    'INF' => 'Infinity',
                    '-INF'=> '-Infinity',
                    default => $item,
                });
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write timestamp list items.
     *
     * @param list<float|int> $list
     *
     * @return void
     */
    private function writeTimestampItems(
        array $list,
        string $itemName,
        TimestampFormat $format,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        $gmdateFmt = match ($format) {
            TimestampFormat::DateTime => TimestampFormat::FMT_DATE_TIME,
            TimestampFormat::HttpDate => TimestampFormat::FMT_HTTP_DATE,
            TimestampFormat::EpochSeconds => null,
        };

        if ($nsUri === null) {
            if ($gmdateFmt !== null) {
                foreach ($list as $item) {
                    $this->writer->writeElement(
                        $itemName,
                        \gmdate($gmdateFmt, (int) $item)
                    );
                }
            } else {
                foreach ($list as $item) {
                    $this->writer->writeElement(
                        $itemName,
                        \is_int($item)
                            ? (string) $item
                            : \sprintf('%.6g', $item)
                    );
                }
            }
            return;
        }

        $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
        if ($gmdateFmt !== null) {
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text(\gmdate($gmdateFmt, (int) $item));
                $this->writer->endElement();
            }
        } else {
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text(
                    \is_int($item)
                        ? (string) $item
                        : \sprintf('%.6g', $item)
                );
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write blob list items.
     *
     * @param list<string> $list
     *
     * @return void
     */
    private function writeBlobItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writer->writeElement($itemName, \base64_encode($item));
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text(\base64_encode($item));
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write structure list items.
     *
     * @param list<SerializableStructInterface> $list
     *
     * @return void
     */
    private function writeStructItems(array $list, string $itemName, Schema $listMember): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        // Struct items use the list wrapper name, not the struct schema's xmlName.
        if ($list === []) {
            return;
        }

        /** @var XmlSchemaExtension $structInfo */
        $structInfo = $listMember->getExtension($extKey);

        $ns = $structInfo->xmlNamespace;
        $nsAttr = null;
        $nsUri = null;
        if ($ns !== null && isset($ns['uri'])) {
            $nsAttr = isset($ns['prefix']) ? "xmlns:{$ns['prefix']}" : 'xmlns';
            $nsUri = $ns['uri'];
        }

        $prevWireNames = $this->wireNames;
        $prevMemberComponentTypes = $this->memberComponentTypes;
        $prevNsAttrs = $this->nsAttrs;
        $prevTsFormats = $this->tsFormats;

        $actualInfo = null;
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            if ($nsAttr !== null) {
                $this->writer->writeAttribute($nsAttr, $nsUri);
            }

            if ($actualInfo === null) {
                /** @var XmlSchemaExtension $actualInfo */
                $actualInfo = $item::schema()->getExtension($extKey);
                $this->wireNames = $actualInfo->memberWireNames;
                $this->memberComponentTypes = $actualInfo->memberComponentTypes;
                $this->nsAttrs = $actualInfo->nsAttrs;
                $this->tsFormats = $actualInfo->tsFormats;
            }

            $item->serializeMembers($this);
            $this->writer->endElement();
        }

        $this->wireNames = $prevWireNames;
        $this->memberComponentTypes = $prevMemberComponentTypes;
        $this->nsAttrs = $prevNsAttrs;
        $this->tsFormats = $prevTsFormats;
    }

    /**
     * Write nested list items.
     *
     * @param list<list<mixed>> $list
     *
     * @return void
     */
    private function writeListItems(array $list, string $itemName, Schema $listMember): void
    {
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            $this->writeNestedList($listMember, $item);
            $this->writer->endElement();
        }
    }

    /**
     * Write nested map items.
     *
     * @param list<array<array-key, mixed>> $list
     *
     * @return void
     */
    private function writeMapItems(array $list, string $itemName, Schema $listMember): void
    {
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            $this->writeNestedMap($listMember, $item);
            $this->writer->endElement();
        }
    }

    /**
     * Write generic list items with per-item dispatch.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeGenericItems(
        array $list,
        string $itemName,
        Schema $listMember,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            if ($nsUri !== null) {
                $this->writer->writeAttribute(
                    $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns',
                    $nsUri
                );
            }
            $this->writeScalarOrNested($listMember, $item, $listMember->type);
            $this->writer->endElement();
        }
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Serialize a member value by shape type.
     *
     * @return void
     */
    private function writeMemberValue(Schema $memberSchema, mixed $value): void
    {
        $idx = $memberSchema->memberIndex;
        switch ($memberSchema->type) {
            case 15: // ShapeType::Structure
                $this->serializeStruct($memberSchema, $value, $idx);
                return;
            case 13: // ShapeType::List_
                $this->serializeList(
                    $memberSchema,
                    $value,
                    $idx,
                    ShapeType::from($memberSchema->listMember->type)
                );
                return;
            case 14: // ShapeType::Map
                $this->serializeMap($memberSchema, $value, $idx);
                return;
            case 1: // ShapeType::Boolean
                $this->serializeBoolean($memberSchema, $value, $idx);
                return;
            case 4:  // ShapeType::Integer
            case 5:  // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                $this->serializeInteger($memberSchema, $value, $idx);
                return;
            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                $this->serializeFloat($memberSchema, $value, $idx);
                return;
            case 8: // ShapeType::BigInteger
                $this->serializeBigInteger($memberSchema, $value, $idx);
                return;
            case 9: // ShapeType::BigDecimal
                $this->serializeBigDecimal($memberSchema, $value, $idx);
                return;
            case 11: // ShapeType::Blob
                $this->serializeBlob($memberSchema, $value, $idx);
                return;
            case 12: // ShapeType::Timestamp
                $this->serializeTimestamp($memberSchema, $value, $idx);
                return;
            case 10: // ShapeType::String_
                $this->serializeString(
                    $memberSchema,
                    $value instanceof EnumInterface
                        ? (string) $value->getValue()
                        : (string) $value,
                    $idx
                );
                return;
            case 18: // ShapeType::IntEnum
                $this->serializeInteger(
                    $memberSchema,
                    $value instanceof EnumInterface
                        ? (int) $value->getValue()
                        : (int) $value,
                    $idx
                );
                return;
            case 16: // ShapeType::Union
                $this->serializeUnion($memberSchema, $value, $idx);
                return;
            default:
                $this->serializeString($memberSchema, (string) $value, $idx);
                return;
        }
    }

    /**
     * Write a scalar or nested value inline.
     *
     * @return void
     */
    private function writeScalarOrNested(Schema $schema, mixed $value, int $type): void
    {
        switch ($type) {
            case 15: // ShapeType::Structure
                $this->writeStructInline($schema, $value);
                return;
            case 13: // ShapeType::List_
                $this->writeNestedList($schema, $value);
                return;
            case 14: // ShapeType::Map
                $this->writeNestedMap($schema, $value);
                return;
            case 1: // ShapeType::Boolean
                $this->writer->text($value ? 'true' : 'false');
                return;
            case 4:  // ShapeType::Integer
            case 5:  // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
            case 18:  // ShapeType::IntEnum
                $this->writer->text(
                    (string) (
                        $value instanceof EnumInterface
                            ? $value->getValue()
                            : $value
                    )
                );
                return;
            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                $this->writer->text((string) $value);
                return;
            case 8: // ShapeType::BigInteger
            case 9: // ShapeType::BigDecimal
                $this->writer->text($value->value);
                return;
            case 11: // ShapeType::Blob
                $this->writer->text(\base64_encode($value));
                return;
            case 12: // ShapeType::Timestamp
                $this->writer->text(
                    (
                        $this->tsFormats[$schema->memberIndex]
                        ?? TimestampFormat::DateTime
                    )->format($value)
                );
                return;
            case 17: // ShapeType::Enum
                $this->writer->text(
                    $value instanceof EnumInterface
                        ? (string) $value->getValue()
                        : (string) $value
                );
                return;
            default:
                $this->writer->text((string) $value);
                return;
        }
    }

    /**
     * Write a nested structure without its wrapper element.
     *
     * @return void
     */
    private function writeStructInline(
        Schema $schema,
        SerializableStructInterface $struct
    ): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $structInfo */
        $structInfo = $schema->getExtension($extKey);

        $prevWireNames = $this->wireNames;
        $prevMemberComponentTypes = $this->memberComponentTypes;
        $prevNsAttrs = $this->nsAttrs;
        $prevTsFormats = $this->tsFormats;

        $this->wireNames = $structInfo->memberWireNames;
        $this->memberComponentTypes = $structInfo->memberComponentTypes;
        $this->nsAttrs = $structInfo->nsAttrs;
        $this->tsFormats = $structInfo->tsFormats;

        $struct->serializeMembers($this);

        $this->wireNames = $prevWireNames;
        $this->memberComponentTypes = $prevMemberComponentTypes;
        $this->nsAttrs = $prevNsAttrs;
        $this->tsFormats = $prevTsFormats;
    }

    /**
     * Write a nested list value.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeNestedList(Schema $schema, array $list): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $listMember = $schema->getListMember();
        $memberName = $info->listMemberName ?? 'member';

        foreach ($list as $item) {
            $this->writer->startElement($memberName);
            if ($listMember !== null) {
                $this->writeScalarOrNested(
                    $listMember,
                    $item,
                    $listMember->type
                );
            } else {
                $this->writer->text((string) $item);
            }
            $this->writer->endElement();
        }
    }

    /**
     * Write a nested map value.
     *
     * @param array<array-key, mixed> $map
     *
     * @return void
     */
    private function writeNestedMap(Schema $schema, array $map): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $mapValue = $schema->getMapValue();
        $keyName = $info->mapKeyName ?? 'key';
        $valueName = $info->mapValueName ?? 'value';

        foreach ($map as $key => $value) {
            $this->writer->startElement('entry');
            $this->writer->writeElement($keyName, (string) $key);
            $this->writer->startElement($valueName);
            if ($mapValue !== null) {
                $this->writeScalarOrNested($mapValue, $value, $mapValue->type);
            } else {
                $this->writer->text((string) $value);
            }
            $this->writer->endElement();
            $this->writer->endElement();
        }
    }

    /**
     * Write the namespace declaration for an XML element.
     *
     * @return void
     */
    private function applyXmlNamespace(XmlSchemaExtension $info): void
    {
        $ns = $info->xmlNamespace;
        if ($ns === null) {
            return;
        }

        if (isset($ns['uri'])) {
            $prefix = $ns['prefix'] ?? null;
            if ($prefix !== null) {
                $this->writer->writeAttribute("xmlns:{$prefix}", $ns['uri']);
            } else {
                $this->writer->writeAttribute('xmlns', $ns['uri']);
            }
        }
    }

}
