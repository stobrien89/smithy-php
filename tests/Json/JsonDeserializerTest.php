<?php

declare(strict_types=1);

namespace Smithy\Tests\Json;

use PHPUnit\Framework\TestCase;
use Smithy\Core\Serde\{
    Schema,
    ShapeType
};
use Smithy\Core\Shape\BigInteger;
use Smithy\Json\JsonCodec;
use Smithy\Tests\Json\Fixture\{
    JsonEnumStructFixture,
    JsonIntEnumFixture,
    JsonSampleUnionFixture,
    JsonStringEnumFixture,
    SampleStruct
};

final class JsonDeserializerTest extends TestCase
{
    public function testCodecUsesRawMemberNamesWhenJsonNameIsDisabled(): void
    {
        $struct = new SampleStruct(name: 'alpha', count: 42);

        $codec = new JsonCodec(false);
        $serializer = $codec->createSerializer();
        $struct->serialize($serializer, 0);
        $json = $serializer->flush();

        self::assertSame('{"name":"alpha","count":42}', $json);

        $fields = \array_fill(0, 5, null);
        $codec->createDeserializer($json)->deserialize(
            SampleStruct::schema(),
            $fields
        );
        $output = SampleStruct::fromMembers($fields);

        self::assertSame('alpha', $output->name);
        self::assertSame(42, $output->count);
    }

    public function testDeserializerHydratesEnumTargetsForMembersListsAndMaps(): void
    {
        $wire = \json_encode([
            'status' => 'open',
            'priority' => 7,
            'statuses' => ['open', 'closed'],
            'priorities' => ['primary' => 7, 'secondary' => 9],
        ]);

        $codec = new JsonCodec();
        $fields = \array_fill(0, 4, null);
        $codec->createDeserializer($wire)->deserialize(
            JsonEnumStructFixture::schema(),
            $fields
        );
        $output = JsonEnumStructFixture::fromMembers($fields);

        self::assertInstanceOf(JsonStringEnumFixture::class, $output->status);
        self::assertSame('open', $output->status->getValue());
        self::assertInstanceOf(JsonIntEnumFixture::class, $output->priority);
        self::assertSame(7, $output->priority->getValue());
        self::assertCount(2, $output->statuses);
        self::assertInstanceOf(
            JsonStringEnumFixture::class,
            $output->statuses[0]
        );
        self::assertSame('closed', $output->statuses[1]->getValue());
        self::assertInstanceOf(
            JsonIntEnumFixture::class,
            $output->priorities['primary']
        );
        self::assertSame(9, $output->priorities['secondary']->getValue());
    }

    public function testTopLevelUnionDeserializeUsesSyntheticSchemaSlowPath(): void
    {
        $codec = new JsonCodec(false);
        $serializer = $codec->createSerializer();
        $serializer->serializeUnion(
            JsonSampleUnionFixture::schema(),
            JsonSampleUnionFixture::create('count', 2),
            0
        );
        $json = $serializer->flush();

        self::assertSame('{"count":2}', $json);

        $members = [null];
        $codec->createDeserializer($json)->deserialize(
            JsonSampleUnionFixture::schema(),
            $members
        );

        self::assertInstanceOf(JsonSampleUnionFixture::class, $members[0]);
        self::assertSame('count', $members[0]->tag());
        self::assertSame(2, $members[0]->value());
    }

    public function testDeserializerPreservesLargeBigIntegerTokens(): void
    {
        $schema = new Schema(
            id: 'smithy.test#BigIntegerContainer',
            type: ShapeType::Structure,
            members: [
                new Schema(
                    id: 'smithy.test#BigIntegerContainer$value',
                    type: ShapeType::BigInteger,
                    memberName: 'value',
                    memberIndex: 0
                )
            ]
        );

        $fields = [null];
        (new JsonCodec())->createDeserializer(
            '{"value":9223372036854775807123}'
        )->deserialize($schema, $fields);

        self::assertInstanceOf(BigInteger::class, $fields[0]);
        self::assertSame('9223372036854775807123', $fields[0]->value);
    }
}
