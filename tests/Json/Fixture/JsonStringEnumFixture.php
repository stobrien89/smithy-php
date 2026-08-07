<?php

declare(strict_types=1);

namespace Smithy\Tests\Json\Fixture;

use Smithy\Core\Serde\{
    Schema,
    ShapeSerializerInterface,
    ShapeType
};
use Smithy\Core\Shape\EnumInterface;

final class JsonStringEnumFixture implements EnumInterface
{
    private static ?Schema $SCHEMA = null;

    /** @var array<string, self> */
    private static array $known = [];

    /**
     * Create a string-enum fixture value.
     */
    private function __construct(
        private readonly string $value
    ) {}

    /**
     * Return the known `open` enum value.
     */
    public static function open(): self
    {
        return self::$known['open'] ??= new self('open');
    }

    /**
     * Return the known `closed` enum value.
     */
    public static function closed(): self
    {
        return self::$known['closed'] ??= new self('closed');
    }

    /**
     * Rehydrate the enum fixture from a wire value.
     */
    public static function fromValue(string $value): self
    {
        return match ($value) {
            'open' => self::open(),
            'closed' => self::closed(),
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
     * Return the enum fixture schema.
     */
    public static function schema(): Schema
    {
        return self::$SCHEMA ??= new Schema(
            id: 'smithy.test#JsonStringEnum',
            type: ShapeType::Enum,
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
        $serializer->serializeString(self::schema(), $this->value, $idx);
    }
}
