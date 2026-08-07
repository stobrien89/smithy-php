<?php

declare(strict_types=1);

namespace Smithy\Tests\Json;

use PHPUnit\Framework\TestCase;
use Smithy\Core\Serde\{
    Schema,
    ShapeType,
    TimestampFormat
};
use Smithy\Json\{
    JsonSchemaExtension,
    JsonSchemaExtensionProvider
};

/**
 * Test {@see JsonSchemaExtension} construction through the lazy provider.
 */
final class JsonSchemaExtensionTest extends TestCase
{
    public function testStructMembersBuildBothWireNameDirections(): void
    {
        $schema = new Schema(
            id: 'smithy.example#Foo',
            type: ShapeType::Structure,
            members: [
                new Schema(
                    id: 'smithy.example#Foo$name',
                    type: ShapeType::String_,
                    memberName: 'name',
                    memberIndex: 0
                ),
                // `@jsonName` overrides the wire key without changing the
                // positional member index.
                new Schema(
                    id: 'smithy.example#Foo$age',
                    type: ShapeType::Integer,
                    memberName: 'age',
                    memberIndex: 1,
                    traits: [Schema::T_JSON_NAME => 'ageInYears']
                )
            ]
        );

        /** @var JsonSchemaExtension $info */
        $info = $schema->getExtension(JsonSchemaExtensionProvider::key());

        self::assertSame(
            ['name' => 0, 'ageInYears' => 1],
            $info->memberIndexByWireName
        );
        self::assertSame(
            [0 => 'name', 1 => 'ageInYears'],
            $info->wireNameByMemberIndex
        );
        self::assertSame([], $info->timestampFormats);
    }

    public function testTimestampMembersResolveFormatWithEpochDefault(): void
    {
        $schema = new Schema(
            id: 'smithy.example#Events',
            type: ShapeType::Structure,
            members: [
                // No @timestampFormat → restJson1 default of epoch-seconds.
                new Schema(
                    id: 'smithy.example#Events$createdAt',
                    type: ShapeType::Timestamp,
                    memberName: 'createdAt',
                    memberIndex: 0
                ),
                // Explicit @timestampFormat overrides the default.
                new Schema(
                    id: 'smithy.example#Events$lastSeen',
                    type: ShapeType::Timestamp,
                    memberName: 'lastSeen',
                    memberIndex: 1,
                    traits: [Schema::T_TIMESTAMP_FORMAT => 'http-date']
                )
            ]
        );

        /** @var JsonSchemaExtension $info */
        $info = $schema->getExtension(JsonSchemaExtensionProvider::key());

        self::assertSame(
            TimestampFormat::EpochSeconds,
            $info->timestampFormats[0]
        );
        self::assertSame(TimestampFormat::HttpDate, $info->timestampFormats[1]);
    }

    public function testNonStructSchemasReturnNull(): void
    {
        // Scalar schemas still return null. The JSON codec only builds
        // extensions for shapes it dispatches on directly.
        $scalarSchema = new Schema(
            id: 'smithy.example#JustAString',
            type: ShapeType::String_
        );

        self::assertNull(
            $scalarSchema->getExtension(JsonSchemaExtensionProvider::key())
        );
    }

    public function testListSchemasCarryListMemberInfo(): void
    {
        // List schemas get a lightweight extension carrying the
        // pre-resolved inner shape type + timestamp format for the
        // deserializer's `readList` fast-path dispatch. Non-timestamp
        // lists leave `listMemberTimestampFormat` null.
        $listSchema = new Schema(
            id: 'smithy.example#StringList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.example#StringList$member',
                type: ShapeType::String_,
                memberName: 'member',
                memberIndex: 0
            )
        );

        /** @var JsonSchemaExtension|null $info */
        $info = $listSchema->getExtension(JsonSchemaExtensionProvider::key());
        self::assertNotNull($info);
        self::assertSame(ShapeType::String_->value, $info->listMemberType);
        self::assertNull($info->listMemberTimestampFormat);
    }

    public function testTimestampListCarriesResolvedFormat(): void
    {
        // Timestamp-typed list members resolve the format at provider
        // time. Absent an explicit `@timestampFormat`, the default is
        // epoch-seconds per the Smithy JSON timestamp default.
        $listSchema = new Schema(
            id: 'smithy.example#TimestampList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.example#TimestampList$member',
                type: ShapeType::Timestamp,
                memberName: 'member',
                memberIndex: 0
            )
        );

        /** @var JsonSchemaExtension|null $info */
        $info = $listSchema->getExtension(JsonSchemaExtensionProvider::key());
        self::assertNotNull($info);
        self::assertSame(ShapeType::Timestamp->value, $info->listMemberType);
        self::assertSame(
            TimestampFormat::EpochSeconds,
            $info->listMemberTimestampFormat
        );
    }

    public function testMapSchemasCarryMapValueInfo(): void
    {
        $mapSchema = new Schema(
            id: 'smithy.example#TimestampMap',
            type: ShapeType::Map,
            traits: [Schema::T_SPARSE => true],
            mapKey: new Schema(
                id: 'smithy.example#TimestampMap$key',
                type: ShapeType::String_,
                memberName: 'key',
                memberIndex: 0
            ),
            mapValue: new Schema(
                id: 'smithy.example#TimestampMap$value',
                type: ShapeType::Timestamp,
                memberName: 'value',
                memberIndex: 1,
                traits: [Schema::T_TIMESTAMP_FORMAT => 'http-date']
            )
        );

        /** @var JsonSchemaExtension|null $info */
        $info = $mapSchema->getExtension(JsonSchemaExtensionProvider::key());
        self::assertNotNull($info);
        self::assertSame(ShapeType::Timestamp->value, $info->mapValueType);
        self::assertSame(
            TimestampFormat::HttpDate,
            $info->mapValueTimestampFormat
        );
        self::assertTrue($info->sparse);
    }

    public function testUnionsBuildTheSameMaps(): void
    {
        $unionSchema = new Schema(
            id: 'smithy.example#Choice',
            type: ShapeType::Union,
            members: [
                new Schema(
                    id: 'smithy.example#Choice$str',
                    type: ShapeType::String_,
                    memberName: 'str',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.example#Choice$num',
                    type: ShapeType::Integer,
                    memberName: 'num',
                    memberIndex: 1,
                    traits: [Schema::T_JSON_NAME => 'number']
                )
            ]
        );

        /** @var JsonSchemaExtension $info */
        $info = $unionSchema->getExtension(JsonSchemaExtensionProvider::key());

        self::assertSame(
            ['str' => 0, 'number' => 1],
            $info->memberIndexByWireName
        );
        self::assertSame(
            [0 => 'str', 1 => 'number'],
            $info->wireNameByMemberIndex
        );
    }
}
