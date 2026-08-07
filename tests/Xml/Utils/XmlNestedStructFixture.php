<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml\Utils;

use Smithy\Core\Serde\{
    Schema,
    SerializableStructInterface,
    ShapeSerializerInterface,
    ShapeType
};
use Smithy\Core\Shape\FromMembersTrait;

final class XmlNestedStructFixture implements SerializableStructInterface
{
    use FromMembersTrait;

    private static ?Schema $SCHEMA = null;

    public function __construct(
        public readonly ?string $value = null
    ) {}

    public static function schema(): Schema
    {
        if (self::$SCHEMA !== null) {
            return self::$SCHEMA;
        }

        self::$SCHEMA = new Schema(
            id: 'smithy.test#NestedStruct',
            type: ShapeType::Structure,
            targetClass: self::class,
            members: [
                new Schema(
                    id: 'smithy.test#NestedStruct$value',
                    type: ShapeType::String_,
                    memberName: 'value',
                    memberIndex: 0
                ),
            ]
        );

        return self::$SCHEMA;
    }

    public function serialize(ShapeSerializerInterface $serializer, int $idx): void
    {
        $serializer->serializeStruct(self::schema(), $this, $idx);
    }

    public function serializeMembers(ShapeSerializerInterface $serializer): void
    {
        if ($this->value === null) {
            return;
        }

        $members = self::schema()->getMembersByIndex();
        $serializer->serializeString($members[0], $this->value, 0);
    }

    public function getMemberValue(Schema $member): mixed
    {
        return $member->memberIndex === 0 ? $this->value : null;
    }
}
