<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml;

use PHPUnit\Framework\TestCase;
use Smithy\Core\Serde\{
    Schema,
    ShapeType,
    TimestampFormat
};
use Smithy\Xml\{
    XmlSchemaExtension,
    XmlSchemaExtensionProvider
};

/**
 * Test {@see XmlSchemaExtension} construction through the lazy provider.
 */
final class XmlSchemaExtensionTest extends TestCase
{
    private static function key(): \Smithy\Core\Serde\SchemaExtensionKey
    {
        return XmlSchemaExtensionProvider::key();
    }

    public function testStructInfoResolvesElementMembers(): void
    {
        $schema = new Schema(
            id: 'test#MyStruct',
            type: ShapeType::Structure,
            members: [
                new Schema(
                    id: 'smithy.api#String',
                    type: ShapeType::String_,
                    memberName: 'name',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.api#Integer',
                    type: ShapeType::Integer,
                    memberName: 'age',
                    memberIndex: 1
                ),
                new Schema(
                    id: 'smithy.api#String',
                    type: ShapeType::String_,
                    memberName: 'renamed',
                    memberIndex: 2,
                    traits: [Schema::T_XML_NAME => 'RenamedField']
                ),
            ],
        );

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension(self::key());

        $this->assertNotNull($info);
        $this->assertSame('MyStruct', $info->xmlName);
        $this->assertSame(
            ['name', 'age', 'RenamedField'],
            array_keys($info->elementMembers)
        );
        $this->assertSame(0, $info->elementMembers['name']->memberIndex);
        $this->assertSame(1, $info->elementMembers['age']->memberIndex);
        $this->assertSame(
            2,
            $info->elementMembers['RenamedField']->memberIndex
        );
        $this->assertSame([], $info->attributeMembers);
        $this->assertSame([], $info->flattenedLists);
    }

    public function testStructInfoClassifiesAttributes(): void
    {
        $schema = new Schema(
            id: 'test#WithAttr',
            type: ShapeType::Structure,
            members: [
                new Schema(
                    id: 'smithy.api#String',
                    type: ShapeType::String_,
                    memberName: 'foo',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.api#String',
                    type: ShapeType::String_,
                    memberName: 'bar',
                    memberIndex: 1,
                    traits: [
                        Schema::T_XML_ATTRIBUTE => true,
                        Schema::T_XML_NAME => 'barAttr',
                    ]
                ),
            ],
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame(['foo'], array_keys($info->elementMembers));
        $this->assertSame(0, $info->elementMembers['foo']->memberIndex);
        $this->assertSame(['barAttr'], array_keys($info->attributeMembers));
        $this->assertSame(1, $info->attributeMembers['barAttr']->memberIndex);
        $this->assertSame(
            [
                0 => XmlSchemaExtension::COMPONENT_ELEMENT,
                1 => XmlSchemaExtension::COMPONENT_ATTRIBUTE,
            ],
            $info->memberComponentTypes,
        );
    }

    public function testStructInfoResolvesFlattenedLists(): void
    {
        $listMember = new Schema(
            id: 'smithy.api#String',
            type: ShapeType::String_
        );
        $listSchema = new Schema(
            id: 'test#StringList',
            type: ShapeType::List_,
            memberName: 'items',
            memberIndex: 0,
            listMember: $listMember,
            traits: [Schema::T_XML_FLATTENED => true],
        );
        $schema = new Schema(
            id: 'test#WithFlatList',
            type: ShapeType::Structure,
            members: [
                $listSchema,
                new Schema(
                    id: 'smithy.api#String',
                    type: ShapeType::String_,
                    memberName: 'name',
                    memberIndex: 1
                ),
            ],
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame(['name'], array_keys($info->elementMembers));
        $this->assertSame(1, $info->elementMembers['name']->memberIndex);
        $this->assertArrayHasKey('items', $info->flattenedLists);
        $this->assertSame(
            $listSchema,
            $info->flattenedLists['items']['member']
        );
        $this->assertSame(
            $listMember,
            $info->flattenedLists['items']['listMember']
        );
    }

    public function testStructInfoResolvesFlattenedMaps(): void
    {
        $mapKey = new Schema(
            id: 'smithy.api#String',
            type: ShapeType::String_
        );
        $mapValue = new Schema(
            id: 'smithy.api#String',
            type: ShapeType::String_
        );
        $mapSchema = new Schema(
            id: 'test#StringMap',
            type: ShapeType::Map,
            memberName: 'tags',
            memberIndex: 0,
            mapKey: $mapKey,
            mapValue: $mapValue,
            traits: [Schema::T_XML_FLATTENED => true],
        );
        $schema = new Schema(
            id: 'test#WithFlatMap',
            type: ShapeType::Structure,
            members: [$mapSchema],
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame([], $info->elementMembers);
        $this->assertArrayHasKey('tags', $info->flattenedMaps);
        $this->assertSame($mapSchema, $info->flattenedMaps['tags']['member']);
        $this->assertSame('key', $info->flattenedMaps['tags']['keyName']);
        $this->assertSame('value', $info->flattenedMaps['tags']['valueName']);
        $this->assertSame($mapValue, $info->flattenedMaps['tags']['mapValue']);
    }

    public function testStructInfoResolvesXmlNamespace(): void
    {
        $schema = new Schema(
            id: 'test#NsStruct',
            type: ShapeType::Structure,
            members: [],
            traits: [
                Schema::T_XML_NAMESPACE => [
                    'uri' => 'http://example.com',
                    'prefix' => 'ex',
                ],
            ],
        );

        $info = $schema->getExtension(self::key());

        $this->assertNotNull($info->xmlNamespace);
        $this->assertSame('http://example.com', $info->xmlNamespace['uri']);
        $this->assertSame('ex', $info->xmlNamespace['prefix']);
    }

    public function testStructInfoResolvesXmlNameFromTrait(): void
    {
        $schema = new Schema(
            id: 'test#OriginalName',
            type: ShapeType::Structure,
            members: [],
            traits: [Schema::T_XML_NAME => 'CustomName'],
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame('CustomName', $info->xmlName);
    }

    public function testListInfoResolvesNonFlattened(): void
    {
        $schema = new Schema(
            id: 'test#MyList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.api#String',
                type: ShapeType::String_,
                traits: [Schema::T_XML_NAME => 'item']
            ),
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame('item', $info->listMemberName);
        $this->assertFalse($info->flattened);
    }

    public function testListInfoDefaultsMemberNameToMember(): void
    {
        $schema = new Schema(
            id: 'test#DefaultList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.api#String',
                type: ShapeType::String_
            ),
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame('member', $info->listMemberName);
        $this->assertFalse($info->flattened);
    }

    public function testListInfoResolvesRootXmlMetadataAndTimestampFormat(): void
    {
        $schema = new Schema(
            id: 'test#TimestampList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.api#Timestamp',
                type: ShapeType::Timestamp,
                traits: [Schema::T_TIMESTAMP_FORMAT => 'epoch-seconds']
            ),
            traits: [
                Schema::T_XML_NAMESPACE => [
                    'uri' => 'urn:items',
                    'prefix' => 'i',
                ],
            ],
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame('TimestampList', $info->xmlName);
        $this->assertSame('urn:items', $info->xmlNamespace['uri']);
        $this->assertSame('i', $info->xmlNamespace['prefix']);
        $this->assertSame('member', $info->listMemberName);
        $this->assertSame(
            TimestampFormat::EpochSeconds,
            $info->listMemberTimestampFormat
        );
    }

    public function testMapInfoResolvesKeyValueNames(): void
    {
        $schema = new Schema(
            id: 'test#MyMap',
            type: ShapeType::Map,
            mapKey: new Schema(
                id: 'smithy.api#String',
                type: ShapeType::String_,
                traits: [Schema::T_XML_NAME => 'K']
            ),
            mapValue: new Schema(
                id: 'smithy.api#String',
                type: ShapeType::String_,
                traits: [Schema::T_XML_NAME => 'V']
            ),
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame('K', $info->mapKeyName);
        $this->assertSame('V', $info->mapValueName);
        $this->assertSame('entry', $info->mapEntryName);
        $this->assertFalse($info->flattened);
    }

    public function testMapInfoDefaultsKeyValueNames(): void
    {
        $schema = new Schema(
            id: 'test#DefaultMap',
            type: ShapeType::Map,
            mapKey: new Schema(
                id: 'smithy.api#String',
                type: ShapeType::String_
            ),
            mapValue: new Schema(
                id: 'smithy.api#String',
                type: ShapeType::String_
            ),
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame('key', $info->mapKeyName);
        $this->assertSame('value', $info->mapValueName);
        $this->assertSame('entry', $info->mapEntryName);
    }

    public function testExtensionReturnsNullForScalarTypes(): void
    {
        $schema = new Schema(id: 'smithy.api#String', type: ShapeType::String_);
        $info = $schema->getExtension(self::key());
        $this->assertNull($info);
    }

    public function testExtensionIsCachedAcrossCalls(): void
    {
        $schema = new Schema(
            id: 'test#Cached',
            type: ShapeType::Structure,
            members: []
        );

        $info1 = $schema->getExtension(self::key());
        $info2 = $schema->getExtension(self::key());

        $this->assertSame($info1, $info2);
    }

    public function testMemberWireNamesAreIndexed(): void
    {
        $schema = new Schema(
            id: 'test#WireNames',
            type: ShapeType::Structure,
            members: [
                new Schema(
                    id: 'smithy.api#String',
                    type: ShapeType::String_,
                    memberName: 'alpha',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.api#String',
                    type: ShapeType::String_,
                    memberName: 'beta',
                    memberIndex: 1,
                    traits: [Schema::T_XML_NAME => 'BetaRenamed']
                ),
            ],
        );

        $info = $schema->getExtension(self::key());

        $this->assertSame(
            [0 => 'alpha', 1 => 'BetaRenamed'],
            $info->memberWireNames
        );
    }
}
