<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml\Utils;

use Smithy\Core\Serde\{
    Schema,
    ShapeSerializerInterface,
    ShapeType
};
use Smithy\Core\Shape\EnumInterface;

final class XmlIntEnumFixture implements EnumInterface
{
    private static ?Schema $SCHEMA = null;

    /** @var array<int, self> */
    private static array $known = [];

    private function __construct(
        private readonly int $value
    ) {}

    public static function seven(): self
    {
        return self::$known[7] ??= new self(7);
    }

    public static function nine(): self
    {
        return self::$known[9] ??= new self(9);
    }

    public static function fromValue(int $value): self
    {
        return match ($value) {
            7 => self::seven(),
            9 => self::nine(),
            default => new self($value),
        };
    }

    public function getValue(): string|int
    {
        return $this->value;
    }

    public static function schema(): Schema
    {
        return self::$SCHEMA ??= new Schema(
            id: 'smithy.test#XmlIntEnum',
            type: ShapeType::IntEnum,
            targetClass: self::class
        );
    }

    public function serialize(ShapeSerializerInterface $serializer, int $idx): void
    {
        $serializer->serializeInteger(self::schema(), $this->value, $idx);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
