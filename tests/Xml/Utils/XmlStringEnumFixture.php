<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml\Utils;

use Smithy\Core\Serde\{
    Schema,
    ShapeSerializerInterface,
    ShapeType
};
use Smithy\Core\Shape\EnumInterface;

final class XmlStringEnumFixture implements EnumInterface
{
    private static ?Schema $SCHEMA = null;

    /** @var array<string, self> */
    private static array $known = [];

    private function __construct(
        private readonly string $value
    ) {}

    public static function open(): self
    {
        return self::$known['open'] ??= new self('open');
    }

    public static function closed(): self
    {
        return self::$known['closed'] ??= new self('closed');
    }

    public static function fromValue(string $value): self
    {
        return match ($value) {
            'open' => self::open(),
            'closed' => self::closed(),
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
            id: 'smithy.test#XmlStringEnum',
            type: ShapeType::Enum,
            targetClass: self::class
        );
    }

    public function serialize(ShapeSerializerInterface $serializer, int $idx): void
    {
        $serializer->serializeString(self::schema(), $this->value, $idx);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
