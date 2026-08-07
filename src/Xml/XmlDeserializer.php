<?php

declare(strict_types=1);

namespace Smithy\Xml;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableShapeInterface;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;

/**
 * Deserialize XML into Smithy shapes with a C-extension tree parser.
 *
 * Parses the document into an in-memory tree once, then traverses that
 * tree with schema-guided member dispatch.
 *
 * @internal Instantiated only by {@see XmlCodec}. External callers should
 *           depend on {@see ShapeDeserializerInterface}. The concrete parser
 *           choice is a private detail of the codec.
 */
final class XmlDeserializer implements ShapeDeserializerInterface
{
    /**
     * Construct the deserializer.
     *
     * @param \SimpleXMLElement $element Parsed XML root element.
     */
    public function __construct(
        private \SimpleXMLElement $element
    ) {}

    /**
     * Deserialize an XML payload into members.
     *
     * @param array<int, mixed> $members
     *
     * @return void
     */
    public function deserialize(Schema $schema, array &$members): void
    {
        if ($schema->type === 16) { // ShapeType::Union
            // httpPayload union bodies use the current root as the union payload.
            $members[0] = $this->readUnionFromElement($this->element, $schema);

            return;
        }

        $this->readStructFromElement($this->element, $schema, $members);
    }

    /**
     * Read structure members from an element.
     *
     * @param array<int, mixed> $out
     *
     * @return void
     */
    private function readStructFromElement(
        \SimpleXMLElement $element,
        Schema $schema,
        array &$out
    ): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);

        // Attribute decode metadata is prepacked on the extension.
        if ($info->hasAttributes) {
            $attrs = $element->attributes();
            if ($attrs !== null) {
                foreach ($info->attributeDeserMembers as $entry) {
                    $attrName = $entry[0];
                    $attrValue = $attrs->{$attrName};
                    if ($attrValue !== null) {
                        $value = (string) $attrValue;
                        $idx = $entry[1];
                        $memberSchema = $info->attributeMembers[$attrName]
                            ?? null;
                        switch ($entry[2]) {
                            case 1: // ShapeType::Boolean
                                $out[$idx] = $value === 'true'
                                    || $value === '1';
                                break;
                            case 4: // ShapeType::Integer
                            case 5: // ShapeType::Long
                            case 3: // ShapeType::Short
                            case 2: // ShapeType::Byte
                                $out[$idx] = (int) $value;
                                break;
                            case 18: // ShapeType::IntEnum
                                $intValue = (int) $value;
                                $targetClass = $memberSchema?->targetClass;
                                $out[$idx] = $targetClass !== null
                                    ? $targetClass::fromValue($intValue)
                                    : $intValue;
                                break;
                            case 6: // ShapeType::Float_
                            case 7: // ShapeType::Double
                                $out[$idx] = (float) $value;
                                break;
                            case 12: // ShapeType::Timestamp
                                $out[$idx] = $entry[3]->parse($value);
                                break;
                            case 11: // ShapeType::Blob
                                $out[$idx] = \base64_decode($value, true) ?: '';
                                break;
                            case 17: // ShapeType::Enum
                                $targetClass = $memberSchema?->targetClass;
                                $out[$idx] = $targetClass !== null
                                    ? $targetClass::fromValue($value)
                                    : $value;
                                break;
                            default:
                                $out[$idx] = $value;
                                break;
                        }
                    }
                }
            }
        }

        $ordered = $info->orderedChildMembers;
        $orderedCount = $info->childMemberCount;
        $hint = 0;
        foreach ($element->children() as $childName => $child) {
            if ($hint < $orderedCount && $ordered[$hint][0] === $childName) {
                $entry = $ordered[$hint][1];
                $hint++;
            } else {
                $entry = $info->childMembers[$childName] ?? null;
                if ($entry === null) {
                    continue;
                }
                $scanLimit = $hint + 4 < $orderedCount
                    ? $hint + 4
                    : $orderedCount;
                for ($i = $hint; $i < $scanLimit; $i++) {
                    if ($ordered[$i][0] === $childName) {
                        $hint = $i + 1;
                        break;
                    }
                }
            }
            switch ($entry[0]) {
                case 0:
                    $idx = $entry[1];
                    switch ($entry[3]) {
                        case 10:  // ShapeType::String_
                            $out[$idx] = (string) $child;
                            break;
                        case 17:  // ShapeType::Enum
                            $value = (string) $child;
                            $targetClass = $entry[2]->targetClass;
                            $out[$idx] = $targetClass !== null
                                ? $targetClass::fromValue($value)
                                : $value;
                            break;
                        case 4:  // ShapeType::Integer
                        case 5:  // ShapeType::Long
                        case 3: // ShapeType::Short
                        case 2: // ShapeType::Byte
                            $out[$idx] = (int) (string) $child;
                            break;
                        case 18:  // ShapeType::IntEnum
                            $value = (int) (string) $child;
                            $targetClass = $entry[2]->targetClass;
                            $out[$idx] = $targetClass !== null
                                ? $targetClass::fromValue($value)
                                : $value;
                            break;
                        case 1: // ShapeType::Boolean
                            $s = (string) $child;
                            $out[$idx] = $s === 'true' || $s === '1';
                            break;
                        case 6: // ShapeType::Float_
                        case 7: // ShapeType::Double
                            $s = (string) $child;
                            $out[$idx] = match ($s) {
                                'NaN' => NAN,
                                'Infinity' => INF,
                                '-Infinity' => -INF,
                                default => (float) $s
                            };
                            break;
                        case 11: // ShapeType::Blob
                            $out[$idx] = \base64_decode(
                                (string) $child,
                                true
                            ) ?: '';
                            break;
                        case 12: // ShapeType::Timestamp
                            $out[$idx] = $entry[4]->parse((string) $child);
                            break;
                        case 15: // ShapeType::Structure
                            $out[$idx] = $this->readNestedStructFromElement(
                                $child,
                                $entry[2]
                            );
                            break;
                        case 16: // ShapeType::Union
                            $out[$idx] = $this->readUnionFromElement(
                                $child,
                                $entry[2]
                            );
                            break;
                        case 13: // ShapeType::List_
                            $out[$idx] = $this->readListFromElement(
                                $child,
                                $entry[2]
                            );
                            break;
                        case 14: // ShapeType::Map
                            $out[$idx] = $this->readMapFromElement(
                                $child,
                                $entry[2]
                            );
                            break;
                        default:
                            $out[$idx] = (string) $child;
                            break;
                    }
                    break;
                case 2:
                    $out[$entry[1]][] = $this->readValueFromElement(
                        $child,
                        $entry[3]
                    );
                    break;
                case 3:
                    $key = isset($child->{$entry[3]})
                        ? (string) $child->{$entry[3]}
                        : null;
                    if ($key !== null) {
                        $out[$entry[1]][$key] = isset($child->{$entry[4]})
                            ? $this->readValueFromElement(
                                $child->{$entry[4]},
                                $entry[5]
                            )
                            : null;
                    }
                    break;
            }
        }
    }

    /**
     * Read a list value from an element.
     *
     * @return list<mixed>
     */
    private function readListFromElement(
        \SimpleXMLElement $element,
        Schema $schema,
        ?XmlSchemaExtension $info = null
    ): array
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info ??= $schema->getExtension($extKey);

        $listMember = $info->listMemberSchema;
        $memberName = $info->listMemberName ?? 'member';
        $memberType = $info->listMemberType ?? ShapeType::String_->value;
        $result = [];

        switch ($memberType) {
            case 10:  // ShapeType::String_
                foreach ($element->{$memberName} as $child) {
                    $result[] = (string) $child;
                }
                break;
            case 17:  // ShapeType::Enum
                $class = $listMember->targetClass;
                foreach ($element->{$memberName} as $child) {
                    $result[] = $class::fromValue((string) $child);
                }
                break;
            case 4:  // ShapeType::Integer
            case 5:  // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                foreach ($element->{$memberName} as $child) {
                    $result[] = (int) (string) $child;
                }
                break;
            case 18:  // ShapeType::IntEnum
                $class = $listMember->targetClass;
                foreach ($element->{$memberName} as $child) {
                    $result[] = $class::fromValue((int) (string) $child);
                }
                break;
            case 8: // ShapeType::BigInteger
                foreach ($element->{$memberName} as $child) {
                    $result[] = new BigInteger((string) $child);
                }
                break;
            case 9: // ShapeType::BigDecimal
                foreach ($element->{$memberName} as $child) {
                    $result[] = new BigDecimal((string) $child);
                }
                break;
            case 1: // ShapeType::Boolean
                foreach ($element->{$memberName} as $child) {
                    $result[] = ($s = (string) $child) === 'true' || $s === '1';
                }
                break;
            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                foreach ($element->{$memberName} as $child) {
                    $s = (string) $child;
                    $result[] = match ($s) {
                        'NaN' => NAN,
                        'Infinity' => INF,
                        '-Infinity' => -INF,
                        default => (float) $s
                    };
                }
                break;
            case 12: // ShapeType::Timestamp
                $fmt = $info->listMemberTimestampFormat;
                foreach ($element->{$memberName} as $child) {
                    $result[] = $fmt->parse((string) $child);
                }
                break;
            case 15: // ShapeType::Structure
                $memberInfo = $info->listMemberExtension;
                foreach ($element->{$memberName} as $child) {
                    $result[] = $this->readNestedStructFromElement(
                        $child,
                        $listMember,
                        $memberInfo
                    );
                }
                break;
            case 16: // ShapeType::Union
                $memberInfo = $info->listMemberExtension;
                foreach ($element->{$memberName} as $child) {
                    $result[] = $this->readUnionFromElement(
                        $child,
                        $listMember,
                        $memberInfo
                    );
                }
                break;
            default:
                foreach ($element->{$memberName} as $child) {
                    $result[] = $this->readValueFromElement(
                        $child,
                        $listMember
                    );
                }
        }

        return $result;
    }

    /**
     * Read a map value from an element.
     *
     * @return array<string, mixed>
     */
    private function readMapFromElement(
        \SimpleXMLElement $element,
        Schema $schema,
        ?XmlSchemaExtension $info = null
    ): array
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info ??= $schema->getExtension($extKey);

        $mapValue = $info->mapValueSchema;
        $keyName = $info->mapKeyName;
        $valueName = $info->mapValueName;
        $entryName = $info->mapEntryName;
        $result = [];

        if ($mapValue !== null && $mapValue->type === 12) {
            $fmt = $info->mapValueTimestampFormat;
            foreach ($element->{$entryName} as $entry) {
                $key = isset($entry->{$keyName})
                    ? (string) $entry->{$keyName}
                    : null;
                if ($key === null) {
                    continue;
                }
                $result[$key] = isset($entry->{$valueName})
                    ? $fmt->parse((string) $entry->{$valueName})
                    : null;
            }
            return $result;
        }

        foreach ($element->{$entryName} as $entry) {
            $key = isset($entry->{$keyName})
                ? (string) $entry->{$keyName}
                : null;
            if ($key !== null) {
                $result[$key] = isset($entry->{$valueName})
                    ? $this->readValueFromElement(
                        $entry->{$valueName},
                        $mapValue
                    )
                    : null;
            }
        }

        return $result;
    }

    /** Read a union value from an element. */
    private function readUnionFromElement(
        \SimpleXMLElement $element,
        Schema $schema,
        ?XmlSchemaExtension $info = null
    ): ?SerializableShapeInterface
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension|null $info */
        $info ??= $schema->getExtension($extKey);
        if ($info === null || $info->memberCount === 0) {
            $iterSchema = $schema->resolveTarget();
            $info = $iterSchema->getExtension($extKey);
        } else {
            $iterSchema = $schema;
        }
        if (!$iterSchema->targetClass) {
            return null;
        }
        $membersByXmlName = $info->unionMembersByXmlName;

        foreach ($element->children() as $childName => $child) {
            if (!($memberSchema = $membersByXmlName[$childName] ?? null)) {
                return ($iterSchema->targetClass)::unknown(
                    $childName,
                    (string) $child
                );
            }

            return ($iterSchema->targetClass)::create(
                $memberSchema->memberName,
                $this->readValueFromElement($child, $memberSchema)
            );
        }

        return null;
    }

    /** Read a scalar or nested value from an element. */
    private function readValueFromElement(\SimpleXMLElement $element, ?Schema $schema): mixed
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        if ($schema === null) {
            return (string) $element;
        }

        switch ($schema->type) {
            case 10:  // ShapeType::String_
                return (string) $element;
            case 17:  // ShapeType::Enum
                return $schema->targetClass::fromValue((string) $element);
            case 4:  // ShapeType::Integer
            case 5:  // ShapeType::Long
            case 3: // ShapeType::Short
            case 2: // ShapeType::Byte
                return (int) (string) $element;
            case 18:  // ShapeType::IntEnum
                return $schema->targetClass::fromValue((int) (string) $element);
            case 8: // ShapeType::BigInteger
                return new BigInteger((string) $element);
            case 9: // ShapeType::BigDecimal
                return new BigDecimal((string) $element);
            case 1: // ShapeType::Boolean
                $s = (string) $element;
                return $s === 'true' || $s === '1';
            case 6: // ShapeType::Float_
            case 7: // ShapeType::Double
                $s = (string) $element;
                return match ($s) {
                    'NaN' => NAN,
                    'Infinity' => INF,
                    '-Infinity' => -INF,
                    default => (float) $s
                };
            case 11: // ShapeType::Blob
                return \base64_decode((string) $element, true) ?: '';
            case 12: // ShapeType::Timestamp
                return (
                    $schema->getExtension($extKey)?->timestampFormat
                        ?? TimestampFormat::DateTime
                )->parse((string) $element);
            case 15: // ShapeType::Structure
                return $this->readNestedStructFromElement($element, $schema);
            case 16: // ShapeType::Union
                return $this->readUnionFromElement($element, $schema);
            case 13: // ShapeType::List_
                return $this->readListFromElement($element, $schema);
            case 14: // ShapeType::Map
                return $this->readMapFromElement($element, $schema);
            default:
                return (string) $element;
        }
    }

    /** Read a nested structure value from an element. */
    private function readNestedStructFromElement(
        \SimpleXMLElement $element,
        Schema $schema,
        ?XmlSchemaExtension $info = null
    ): ?SerializableShapeInterface
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        // Recursive stub members resolve through the target schema here.
        /** @var XmlSchemaExtension|null $info */
        $info ??= $schema->getExtension($extKey);
        if ($info === null || $info->memberCount === 0) {
            $resolved = $schema->resolveTarget();
            $info = $resolved->getExtension($extKey);
        } else {
            $resolved = $schema;
        }

        if ($info !== null) {
            $members = $info->membersTemplate;
        } else {
            $count = $resolved->getMemberCount();
            $members = $count > 0 ? \array_fill(0, $count, null) : [];
        }
        $this->readStructFromElement($element, $resolved, $members);

        return $resolved->targetClass
            ? ($resolved->targetClass)::fromMembers($members)
            : null;
    }
}
