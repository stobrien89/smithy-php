<?php

declare(strict_types=1);

namespace Smithy\Tests\Json\Fixture;

use Smithy\Core\Serde\{
    Schema,
    ShapeSerializerInterface,
    ShapeType
};
use Smithy\Core\Shape\EnumInterface;

final class JsonIntEnumFixture implements EnumInterface
{
    private static ?Schema $SCHEMA = null;

    /** @var array<int, self> */
    private static array $known = [];

    /**
     * Create an int-enum fixture value.
     */
    private function __construct(
        private readonly int $value
    ) {}

    /**
     * Return the known `7` enum value.
     */
    public static function seven(): self
    {
        return self::$known[7] ??= new self(7);
    }

    /**
     * Return the known `9` enum value.
     */
    public static function nine(): self
    {
        return self::$known[9] ??= new self(9);
    }

    /**
     * Rehydrate the enum fixture from a wire value.
     */
    public static function fromValue(int $value): self
    {
        return match ($value) {
            7 => self::seven(),
            9 => self::nine(),
            default => new self($value),
        };
    }

    /**
     * Return the enum's backing value.
     */
    public function getValue(): string|int
    {
        return $this->value;
    }

    /**
     * Return the int-enum fixture schema.
     */
    public static function schema(): Schema
    {
        return self::$SCHEMA ??= new Schema(
            id: 'smithy.test#JsonIntEnum',
            type: ShapeType::IntEnum,
            targetClass: self::class
        );
    }

    /**
     * Serialize the enum fixture.
     *
     * @return void
     */
    public function serialize(ShapeSerializerInterface $serializer, int $idx): void
    {
        $serializer->serializeInteger(self::schema(), $this->value, $idx);
    }
}
