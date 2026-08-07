<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml\Utils;

use Smithy\Core\Serde\{
    Schema,
    ShapeSerializerInterface,
    ShapeType
};
use Smithy\Core\Shape\UnionInterface;

final class XmlSampleUnionFixture implements UnionInterface
{
    private static ?Schema $SCHEMA = null;

    public function __construct(
        private readonly string $activeTag,
        private readonly mixed $activeValue
    ) {}

    public static function schema(): Schema
    {
        if (self::$SCHEMA !== null) {
            return self::$SCHEMA;
        }

        self::$SCHEMA = new Schema(
            id: 'smithy.test#SampleUnion',
            type: ShapeType::Union,
            targetClass: self::class,
            members: [
                new Schema(
                    id: 'smithy.test#SampleUnion$value',
                    type: ShapeType::String_,
                    memberName: 'value',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.test#SampleUnion$count',
                    type: ShapeType::Integer,
                    memberName: 'count',
                    memberIndex: 1
                ),
            ]
        );

        return self::$SCHEMA;
    }

    public function serialize(ShapeSerializerInterface $serializer, int $idx): void
    {
        $serializer->serializeUnion(self::schema(), $this, $idx);
    }

    public function tag(): string
    {
        return $this->activeTag;
    }

    public function value(): mixed
    {
        return $this->activeValue;
    }

    public static function create(string $tag, mixed $value): self
    {
        return new self($tag, $value);
    }

    public static function unknown(string $tag, mixed $value): self
    {
        return new self($tag, $value);
    }
}
