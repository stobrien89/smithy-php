<?php

declare(strict_types=1);

namespace Smithy\Xml;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SchemaExtensionKey;
use Smithy\Core\Serde\SchemaExtensionProviderInterface;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Provide precomputed XML metadata for schemas.
 *
 * Computes {@see XmlSchemaExtension} lazily on first access per schema.
 *
 * @internal Wired into the XML codec runtime by {@see XmlCodec}. External
 *           code should use a separate
 *           {@see SchemaExtensionProviderInterface} for custom codecs.
 */
final class XmlSchemaExtensionProvider
    implements SchemaExtensionProviderInterface
{
    /** Singleton extension key cached for XML serializer and deserializer hot paths. */
    public static ?SchemaExtensionKey $KEY = null;

    /** Return the shared XML extension key. */
    public static function key(): SchemaExtensionKey
    {
        return self::$KEY ??= new SchemaExtensionKey(new self());
    }

    /** Provide XML metadata for a schema. */
    public function provide(Schema $schema): ?XmlSchemaExtension
    {
        if ($schema->type === ShapeType::Structure->value
            || $schema->type === ShapeType::Union->value
        ) {
            return $this->buildStructInfo($schema);
        }

        if ($schema->isMember()) {
            return $this->buildMemberInfo($schema);
        }

        if ($schema->type === ShapeType::List_->value) {
            return $this->buildListInfo($schema);
        }
        if ($schema->type === ShapeType::Map->value) {
            return $this->buildMapInfo($schema);
        }

        $nsTrait = $schema->getTrait(Schema::T_XML_NAMESPACE);
        if ($nsTrait !== null) {
            return new XmlSchemaExtension(
                xmlName: $schema->getTrait(Schema::T_XML_NAME) ?? '',
                xmlNamespace: self::resolveNamespace($nsTrait)
            );
        }

        return null;
    }

    /** Build XML metadata for a member schema. */
    private function buildMemberInfo(Schema $schema): XmlSchemaExtension
    {
        $resolvedTarget = $schema->getMemberCount() === 0
            ? $schema->resolveTarget()
            : $schema;
        $resolvedTargetClass = $schema->targetClass
            ?? $resolvedTarget?->targetClass;
        $resolvedTargetSchema = $resolvedTarget;

        $explicitXmlName = $schema->getTrait(Schema::T_XML_NAME);
        if ($explicitXmlName !== null) {
            $xmlName = $explicitXmlName;
        } elseif (
            $schema->hasTrait(Schema::T_HTTP_PAYLOAD)
            && (
                $schema->type === ShapeType::Structure->value
                || $schema->type === ShapeType::Union->value
            )
        ) {
            $pos = \strpos($schema->id, '#');
            $xmlName = $pos !== false
                ? \substr($schema->id, $pos + 1)
                : ($schema->memberName ?? '');
        } else {
            $xmlName = $schema->memberName ?? '';
        }

        if ($xmlName === '' && $schema->id !== '') {
            $pos = \strpos($schema->id, '#');
            $xmlName = $pos !== false
                ? \substr($schema->id, $pos + 1)
                : $schema->id;
        }
        $isAttribute = $schema->hasTrait(Schema::T_XML_ATTRIBUTE);

        $xmlNamespace = self::resolveNamespace(
            $schema->getTrait(Schema::T_XML_NAMESPACE)
        );

        if ($schema->type === ShapeType::List_->value) {
            $listMember = $schema->getListMember();
            $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);
            $listMemberName = $flattened
                ? $xmlName
                : ($listMember?->getTrait(Schema::T_XML_NAME) ?? 'member');

            $listTsFmt = null;
            if ($listMember !== null
                && $listMember->type === ShapeType::Timestamp->value
            ) {
                $listTsFmt = TimestampFormat::fromTraitString(
                    $listMember->getTrait(Schema::T_TIMESTAMP_FORMAT)
                ) ?? TimestampFormat::DateTime;
            }

            $listMemberExtension = $listMember !== null
                ? $this->provide($listMember)
                : null;

            return new XmlSchemaExtension(
                xmlName: $xmlName,
                xmlNamespace: $xmlNamespace,
                isAttribute: $isAttribute,
                listMemberName: $listMemberName,
                flattened: $flattened,
                listMemberType: $listMember?->type,
                listMemberSchema: $listMember,
                listMemberNamespace: self::resolveNamespace(
                    $listMember?->getTrait(Schema::T_XML_NAMESPACE)
                ),
                listMemberTimestampFormat: $listTsFmt,
                listMemberExtension: $listMemberExtension,
                resolvedTargetClass: $resolvedTargetClass,
                resolvedTargetSchema: $resolvedTargetSchema
            );
        }

        if ($schema->type === ShapeType::Map->value) {
            $mapKey = $schema->getMapKey();
            $mapValue = $schema->getMapValue();
            $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);

            $mapValueTsFmt = null;
            if ($mapValue !== null
                && $mapValue->type === ShapeType::Timestamp->value
            ) {
                $mapValueTsFmt = TimestampFormat::fromTraitString(
                    $mapValue->getTrait(Schema::T_TIMESTAMP_FORMAT),
                ) ?? TimestampFormat::DateTime;
            }
            $mapValueExtension = $mapValue !== null
                ? $this->provide($mapValue)
                : null;

            return new XmlSchemaExtension(
                xmlName: $xmlName,
                xmlNamespace: $xmlNamespace,
                isAttribute: $isAttribute,
                mapKeyName: $mapKey?->getTrait(Schema::T_XML_NAME) ?? 'key',
                mapValueName: $mapValue?->getTrait(Schema::T_XML_NAME)
                    ?? 'value',
                mapEntryName: $flattened ? $xmlName : 'entry',
                flattened: $flattened,
                mapValueSchema: $mapValue,
                mapValueTimestampFormat: $mapValueTsFmt,
                mapValueExtension: $mapValueExtension,
                resolvedTargetClass: $resolvedTargetClass,
                resolvedTargetSchema: $resolvedTargetSchema
            );
        }

        $timestampFormat = null;
        if ($schema->type === ShapeType::Timestamp->value) {
            $timestampFormat = TimestampFormat::fromTraitString(
                $schema->getTrait(Schema::T_TIMESTAMP_FORMAT)
            );
        }

        $mediaType = $schema->getTrait(Schema::T_MEDIA_TYPE);

        return new XmlSchemaExtension(
            xmlName: $xmlName,
            xmlNamespace: $xmlNamespace,
            isAttribute: $isAttribute,
            timestampFormat: $timestampFormat,
            mediaType: $mediaType,
            resolvedTargetClass: $resolvedTargetClass,
            resolvedTargetSchema: $resolvedTargetSchema
        );
    }

    /** Build XML metadata for a structure or union schema. */
    private function buildStructInfo(Schema $schema): XmlSchemaExtension
    {
        $memberWireNames = [];
        $attributeMembers = [];
        $attributeDeserMembers = [];
        $elementMembers = [];
        $flattenedLists = [];
        $flattenedMaps = [];
        $childMembers = [];
        $memberComponentTypes = [];
        $nsAttrs = [];
        $tsFormats = [];
        $unionMembersByXmlName = [];
        $unionMembersByName = [];

        foreach ($schema->getMembers() as $memberSchema) {
            $idx = $memberSchema->memberIndex;
            $xmlName = $memberSchema->getTrait(Schema::T_XML_NAME)
                ?? $memberSchema->memberName;
            $memberWireNames[$idx] = $xmlName;

            $memberComponentTypes[$idx] = XmlSchemaExtension::COMPONENT_ELEMENT;

            // Attribute classification wins when both traits are present.
            $hasNamespace = false;
            $nsTrait = $memberSchema->getTrait(Schema::T_XML_NAMESPACE);
            if ($nsTrait !== null) {
                $nsArr = self::resolveNamespace($nsTrait);
                if ($nsArr !== null && isset($nsArr['uri'])) {
                    $attrName = isset($nsArr['prefix'])
                        ? "xmlns:{$nsArr['prefix']}"
                        : 'xmlns';
                    $uri = $nsArr['uri'];
                    $escapedUri = \strtr($uri, [
                        '&' => '&amp;',
                        '"' => '&quot;',
                        '<' => '&lt;',
                        '>' => '&gt;',
                    ]);
                    $nsAttrs[$idx] = [
                        $attrName,
                        $uri,
                        " {$attrName}=\"{$escapedUri}\""
                    ];
                    $hasNamespace = true;
                }
            }

            $explicitTimestampFormat = null;
            $resolvedTimestampFormat = null;
            if ($memberSchema->type === ShapeType::Timestamp->value) {
                $explicitTimestampFormat = TimestampFormat::fromTraitString(
                    $memberSchema->getTrait(Schema::T_TIMESTAMP_FORMAT)
                );
                if ($explicitTimestampFormat !== null) {
                    $tsFormats[$idx] = $explicitTimestampFormat;
                }
                $resolvedTimestampFormat = $explicitTimestampFormat
                    ?? TimestampFormat::DateTime;
            }

            if ($memberSchema->hasTrait(Schema::T_XML_ATTRIBUTE)) {
                // Attribute beats namespace when both traits are present.
                $memberComponentTypes[$idx] =
                    XmlSchemaExtension::COMPONENT_ATTRIBUTE;
                $attributeMembers[$xmlName] = $memberSchema;
                if ($resolvedTimestampFormat !== null) {
                    $attributeDeserMembers[] = [
                        $xmlName,
                        $idx,
                        12,
                        $resolvedTimestampFormat,
                    ];
                } else {
                    $attributeDeserMembers[] = [
                        $xmlName,
                        $idx,
                        $memberSchema->type,
                    ];
                }
            } elseif (
                $memberSchema->type === ShapeType::List_->value
                && $memberSchema->hasTrait(Schema::T_XML_FLATTENED)
            ) {
                $flatName = $memberSchema->getTrait(Schema::T_XML_NAME)
                    ?? $memberSchema->memberName;
                $listMember = $memberSchema->getListMember();
                $flattenedLists[$flatName] = [
                    'member' => $memberSchema,
                    'listMember' => $listMember
                ];
                $childMembers[$flatName] = [
                    2,
                    $memberSchema->memberIndex,
                    $memberSchema,
                    $listMember,
                ];
            } elseif (
                $memberSchema->type === ShapeType::Map->value
                && $memberSchema->hasTrait(Schema::T_XML_FLATTENED)
            ) {
                $flatName = $memberSchema->getTrait(Schema::T_XML_NAME)
                    ?? $memberSchema->memberName;
                $mapKey = $memberSchema->getMapKey();
                $mapValue = $memberSchema->getMapValue();
                $keyName = $mapKey?->getTrait(Schema::T_XML_NAME) ?? 'key';
                $valueName = $mapValue?->getTrait(Schema::T_XML_NAME)
                    ?? 'value';
                $flattenedMaps[$flatName] = [
                    'member' => $memberSchema,
                    'keyName' => $keyName,
                    'valueName' => $valueName,
                    'mapValue' => $mapValue
                ];
                $childMembers[$flatName] = [
                    3,
                    $memberSchema->memberIndex,
                    $memberSchema,
                    $keyName,
                    $valueName,
                    $mapValue,
                ];
            } else {
                $elementMembers[$xmlName] = $memberSchema;
                if ($hasNamespace) {
                    $memberComponentTypes[$idx] =
                        XmlSchemaExtension::COMPONENT_ELEMENT_WITH_NAMESPACE;
                }
                if ($resolvedTimestampFormat !== null) {
                    $childMembers[$xmlName] = [
                        0,
                        $idx,
                        $memberSchema,
                        12,
                        $resolvedTimestampFormat,
                    ];
                } else {
                    $childMembers[$xmlName] = [
                        0,
                        $idx,
                        $memberSchema,
                        $memberSchema->type,
                    ];
                }
            }

            if ($schema->type === ShapeType::Union->value) {
                $unionMembersByXmlName[$xmlName] = $memberSchema;
                $unionMembersByName[$memberSchema->memberName] = $memberSchema;
            }
        }

        $explicitXmlName = $schema->getTrait(Schema::T_XML_NAME);
        if ($explicitXmlName !== null) {
            $structXmlName = $explicitXmlName;
        } elseif (
            $schema->isMember()
            && $schema->hasTrait(Schema::T_HTTP_PAYLOAD)
        ) {
            $pos = \strpos($schema->id, '#');
            $structXmlName = $pos !== false
                ? \substr($schema->id, $pos + 1)
                : ($schema->memberName ?? '');
        } else {
            $structXmlName = $schema->memberName ?? '';
            if ($structXmlName === '' && $schema->id !== '') {
                $pos = \strpos($schema->id, '#');
                $structXmlName = $pos !== false
                    ? \substr($schema->id, $pos + 1)
                    : $schema->id;
            }
        }

        $xmlNamespace = self::resolveNamespace(
            $schema->getTrait(Schema::T_XML_NAMESPACE)
        );

        $orderedChildMembers = [];
        foreach ($childMembers as $name => $entry) {
            $orderedChildMembers[] = [$name, $entry];
        }

        $hasElementsWithNamespace = false;
        foreach ($memberComponentTypes as $ct) {
            if ($ct === XmlSchemaExtension::COMPONENT_ELEMENT_WITH_NAMESPACE) {
                $hasElementsWithNamespace = true;
                break;
            }
        }

        $memberCount = \count($memberWireNames);
        $membersTemplate = $memberCount > 0
            ? \array_fill(0, $memberCount, null)
            : [];

        return new XmlSchemaExtension(
            xmlName: $structXmlName,
            xmlNamespace: $xmlNamespace,
            memberWireNames: $memberWireNames,
            attributeMembers: $attributeMembers,
            attributeDeserMembers: $attributeDeserMembers,
            elementMembers: $elementMembers,
            flattenedLists: $flattenedLists,
            flattenedMaps: $flattenedMaps,
            childMembers: $childMembers,
            orderedChildMembers: $orderedChildMembers,
            childMemberCount: \count($orderedChildMembers),
            memberComponentTypes: $memberComponentTypes,
            nsAttrs: $nsAttrs,
            hasAttributes: $attributeMembers !== [],
            hasElementsWithNamespace: $hasElementsWithNamespace,
            tsFormats: $tsFormats,
            memberCount: $memberCount,
            membersTemplate: $membersTemplate,
            unionMembersByXmlName: $unionMembersByXmlName,
            unionMembersByName: $unionMembersByName
        );
    }

    /** Build XML metadata for a list schema. */
    private function buildListInfo(Schema $schema): XmlSchemaExtension
    {
        $listMember = $schema->getListMember();
        $explicitXmlName = $schema->getTrait(Schema::T_XML_NAME);
        if ($explicitXmlName !== null) {
            $xmlName = $explicitXmlName;
        } elseif ($schema->memberName !== null) {
            $xmlName = $schema->memberName;
        } else {
            $pos = \strpos($schema->id, '#');
            $xmlName = $pos !== false
                ? \substr($schema->id, $pos + 1)
                : $schema->id;
        }
        $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);
        $memberName = $flattened
            ? $xmlName
            : ($listMember?->getTrait(Schema::T_XML_NAME) ?? 'member');
        $xmlNamespace = self::resolveNamespace(
            $schema->getTrait(Schema::T_XML_NAMESPACE)
        );
        $listTsFmt = null;
        if ($listMember !== null
            && $listMember->type === ShapeType::Timestamp->value
        ) {
            $listTsFmt = TimestampFormat::fromTraitString(
                $listMember->getTrait(Schema::T_TIMESTAMP_FORMAT)
            ) ?? TimestampFormat::DateTime;
        }

        $listMemberExtension = $listMember !== null
            ? $this->provide($listMember)
            : null;

        return new XmlSchemaExtension(
            xmlName: $xmlName,
            xmlNamespace: $xmlNamespace,
            listMemberName: $memberName,
            flattened: $flattened,
            listMemberType: $listMember?->type,
            listMemberSchema: $listMember,
            listMemberNamespace: self::resolveNamespace(
                $listMember?->getTrait(Schema::T_XML_NAMESPACE)
            ),
            listMemberTimestampFormat: $listTsFmt,
            listMemberExtension: $listMemberExtension
        );
    }

    /** Normalize `@xmlNamespace` into `['uri' => ..., 'prefix' => ...]`. */
    private static function resolveNamespace(mixed $nsTrait): ?array
    {
        if ($nsTrait === null) {
            return null;
        }
        if (\is_array($nsTrait)) {
            return $nsTrait;
        }
        if (\is_string($nsTrait)) {
            return \str_starts_with($nsTrait, '{')
                ? (\json_decode($nsTrait, true) ?: null)
                : ['uri' => $nsTrait];
        }

        return null;
    }

    /** Build XML metadata for a map schema. */
    private function buildMapInfo(Schema $schema): XmlSchemaExtension
    {
        $mapKey = $schema->getMapKey();
        $mapValue = $schema->getMapValue();
        $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);

        $mapValueTsFmt = null;
        if ($mapValue !== null
            && $mapValue->type === ShapeType::Timestamp->value
        ) {
            $mapValueTsFmt = TimestampFormat::fromTraitString(
                $mapValue->getTrait(Schema::T_TIMESTAMP_FORMAT),
            ) ?? TimestampFormat::DateTime;
        }
        $mapValueExtension = $mapValue !== null
            ? $this->provide($mapValue)
            : null;

        return new XmlSchemaExtension(
            mapKeyName: $mapKey?->getTrait(Schema::T_XML_NAME) ?? 'key',
            mapValueName: $mapValue?->getTrait(Schema::T_XML_NAME) ?? 'value',
            mapEntryName: $flattened
                ? ($schema->getTrait(Schema::T_XML_NAME) ?? $schema->memberName)
                : 'entry',
            flattened: $flattened,
            mapValueSchema: $mapValue,
            mapValueTimestampFormat: $mapValueTsFmt,
            mapValueExtension: $mapValueExtension
        );
    }
}
