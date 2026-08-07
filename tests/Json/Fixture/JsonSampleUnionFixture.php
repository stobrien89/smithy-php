<?php

declare(strict_types=1);

namespace Smithy\Tests\Json\Fixture;

use Smithy\Core\Serde\{
    Schema,
    ShapeSerializerInterface,
    ShapeType
};
use Smithy\Core\Shape\UnionInterface;

final class JsonSampleUnionFixture implements UnionInterface
{
    private static ?Schema $SCHEMA = null;

    /**
     * Create a union fixture value.
     */
    public function __construct(
        private readonly string $activeTag,
        private readonly mixed $activeValue
    ) {}

    /**
     * Return the union fixture schema.
     */
    public static function schema(): Schema
    {
        if (self::$SCHEMA !== null) {
            return self::$SCHEMA;
        }

        self::$SCHEMA = new Schema(
            id: 'smithy.test#JsonSampleUnion',
            type: ShapeType::Union,
            targetClass: self::class,
            members: [
                new Schema(
                    id: 'smithy.test#JsonSampleUnion$value',
                    type: ShapeType::String_,
                    memberName: 'value',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.test#JsonSampleUnion$count',
                    type: ShapeType::Integer,
                    memberName: 'count',
                    memberIndex: 1,
                    traits: [Schema::T_JSON_NAME => 'Count']
                ),
            ]
        );

        return self::$SCHEMA;
    }

    /**
     * Serialize the union fixture.
     *
     * @return void
     */
    public function serialize(ShapeSerializerInterface $serializer, int $idx): void
    {
        $serializer->serializeUnion(self::schema(), $this, $idx);
    }

    /**
     * Return the active union tag.
     */
    public function tag(): string
    {
        return $this->activeTag;
    }

    /**
     * Return the active union value.
     */
    public function value(): mixed
    {
        return $this->activeValue;
    }

    /**
     * Create a known union variant.
     */
    public static function create(string $tag, mixed $value): self
    {
        return new self($tag, $value);
    }

    /**
     * Create an unknown union variant.
     */
    public static function unknown(string $tag, mixed $value): self
    {
        return new self($tag, $value);
    }
}
