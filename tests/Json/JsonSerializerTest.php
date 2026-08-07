<?php

declare(strict_types=1);

namespace Smithy\Tests\Json;

use PHPUnit\Framework\TestCase;
use Smithy\Core\Serde\{
    Schema,
    ShapeType
};
use Smithy\Core\Shape\{
    BigDecimal,
    BigInteger
};
use Smithy\Json\JsonSerializer;

final class JsonSerializerTest extends TestCase
{
    public function testSerializeTopLevelBigIntegerNormalizesLeadingZeros(): void
    {
        $serializer = new JsonSerializer();
        $serializer->serializeBigInteger(
            new Schema(
                id: 'smithy.test#BigIntegerValue',
                type: ShapeType::BigInteger
            ),
            new BigInteger('0001'),
            0
        );

        self::assertSame('1', $serializer->flush());
    }

    public function testSerializeTopLevelBigIntegerListUsesNumberTokens(): void
    {
        $schema = new Schema(
            id: 'smithy.test#BigIntegerList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.test#BigIntegerList$member',
                type: ShapeType::BigInteger
            )
        );

        $serializer = new JsonSerializer();
        $serializer->serializeList(
            $schema,
            [new BigInteger('0001'), new BigInteger('-0002')],
            0,
            ShapeType::BigInteger
        );

        self::assertSame('[1,-2]', $serializer->flush());
    }

    public function testSerializeTopLevelBigDecimalMapUsesNumberTokens(): void
    {
        $schema = new Schema(
            id: 'smithy.test#BigDecimalMap',
            type: ShapeType::Map,
            mapKey: new Schema(
                id: 'smithy.test#BigDecimalMap$key',
                type: ShapeType::String_
            ),
            mapValue: new Schema(
                id: 'smithy.test#BigDecimalMap$value',
                type: ShapeType::BigDecimal
            )
        );

        $serializer = new JsonSerializer();
        $serializer->serializeMap(
            $schema,
            [
                'a' => new BigDecimal('1.5'),
                'b' => new BigDecimal('2E3'),
            ],
            0
        );

        self::assertSame('{"a":1.5,"b":2E3}', $serializer->flush());
    }

    public function testNestedBigNumberListsUseInlineSerializationPath(): void
    {
        $inner = new Schema(
            id: 'smithy.test#InnerBigIntegerList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.test#InnerBigIntegerList$member',
                type: ShapeType::BigInteger
            )
        );
        $outer = new Schema(
            id: 'smithy.test#OuterBigIntegerList',
            type: ShapeType::List_,
            listMember: $inner
        );

        $serializer = new JsonSerializer();
        $serializer->serializeList(
            $outer,
            [[new BigInteger('0001'), new BigInteger('002')]],
            0,
            ShapeType::List_
        );

        self::assertSame('[[1,2]]', $serializer->flush());
    }

    public function testNestedBigNumberMapsUseInlineSerializationPath(): void
    {
        $inner = new Schema(
            id: 'smithy.test#InnerBigDecimalMap',
            type: ShapeType::Map,
            mapKey: new Schema(
                id: 'smithy.test#InnerBigDecimalMap$key',
                type: ShapeType::String_
            ),
            mapValue: new Schema(
                id: 'smithy.test#InnerBigDecimalMap$value',
                type: ShapeType::BigDecimal
            )
        );
        $outer = new Schema(
            id: 'smithy.test#OuterBigDecimalMap',
            type: ShapeType::Map,
            mapKey: new Schema(
                id: 'smithy.test#OuterBigDecimalMap$key',
                type: ShapeType::String_
            ),
            mapValue: $inner
        );

        $serializer = new JsonSerializer();
        $serializer->serializeMap(
            $outer,
            ['outer' => ['a' => new BigDecimal('1.25')]],
            0
        );

        self::assertSame('{"outer":{"a":1.25}}', $serializer->flush());
    }
}
