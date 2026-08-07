<?php

declare(strict_types=1);

namespace Smithy\Tests\Json\Fixture;

use Smithy\Core\Serde\{
    Schema,
    SerializableStructInterface,
    ShapeSerializerInterface,
    ShapeType
};

/**
 * Provide a structure fixture for the JSON codec tests.
 */
final class SampleStruct implements SerializableStructInterface
{
    private static ?Schema $SCHEMA = null;

    /**
     * Create the sample JSON fixture shape.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?int $count = null,
        public readonly ?bool $active = null,
        /** @var list<string>|null */
        public readonly ?array $tags = null,
        /** @var array<string, int>|null */
        public readonly ?array $scores = null
    ) {}

    /**
     * Return the fixture schema used by the JSON tests.
     */
    public static function schema(): Schema
    {
        if (self::$SCHEMA !== null) {
            return self::$SCHEMA;
        }

        $stringItem = new Schema(
            id: 'smithy.test#Tags$member',
            type: ShapeType::String_,
            memberName: 'member',
            memberIndex: 0
        );
        $list = new Schema(
            id: 'smithy.test#Tags',
            type: ShapeType::List_,
            listMember: $stringItem
        );

        $mapKey = new Schema(
            id: 'smithy.test#Scores$key',
            type: ShapeType::String_,
            memberName: 'key',
            memberIndex: 0
        );
        $mapValue = new Schema(
            id: 'smithy.test#Scores$value',
            type: ShapeType::Integer,
            memberName: 'value',
            memberIndex: 1
        );
        $map = new Schema(
            id: 'smithy.test#Scores',
            type: ShapeType::Map,
            mapKey: $mapKey,
            mapValue: $mapValue
        );

        self::$SCHEMA = new Schema(
            id: 'smithy.test#SampleStruct',
            type: ShapeType::Structure,
            targetClass: self::class,
            members: [
                new Schema(
                    id: 'smithy.test#SampleStruct$name',
                    type: ShapeType::String_,
                    memberName: 'name',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$count',
                    type: ShapeType::Integer,
                    memberName: 'count',
                    memberIndex: 1,
                    traits: [Schema::T_JSON_NAME => 'Count']
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$active',
                    type: ShapeType::Boolean,
                    memberName: 'active',
                    memberIndex: 2
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$tags',
                    type: ShapeType::List_,
                    memberName: 'tags',
                    memberIndex: 3,
                    listMember: $stringItem
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$scores',
                    type: ShapeType::Map,
                    memberName: 'scores',
                    memberIndex: 4,
                    mapKey: $mapKey,
                    mapValue: $mapValue
                )
            ]
        );

        return self::$SCHEMA;
    }

    /**
     * Serialize the fixture as a structure member.
     *
     * @return void
     */
    public function serialize(ShapeSerializerInterface $serializer, int $idx): void
    {
        $serializer->serializeStruct(self::schema(), $this, $idx);
    }

    /**
     * Serialize the fixture's declared members.
     *
     * @return void
     */
    public function serializeMembers(ShapeSerializerInterface $serializer): void
    {
        $members = self::schema()->getMembersByIndex();
        if ($this->name !== null) {
            $serializer->serializeString($members[0], $this->name, 0);
        }
        if ($this->count !== null) {
            $serializer->serializeInteger($members[1], $this->count, 1);
        }
        if ($this->active !== null) {
            $serializer->serializeBoolean($members[2], $this->active, 2);
        }
        if ($this->tags !== null) {
            $serializer->serializeList(
                $members[3],
                $this->tags,
                3,
                ShapeType::String_
            );
        }
        if ($this->scores !== null) {
            $serializer->serializeMap($members[4], $this->scores, 4);
        }
    }

    /**
     * Return the fixture value for the requested member.
     */
    public function getMemberValue(Schema $member): mixed
    {
        return match ($member->memberIndex) {
            0 => $this->name,
            1 => $this->count,
            2 => $this->active,
            3 => $this->tags,
            4 => $this->scores,
            default => null
        };
    }

    /**
     * Rehydrate the fixture from positional members.
     *
     * @param array<int, mixed> $fields
     */
    public static function fromMembers(array $fields): static
    {
        return new self(
            name: $fields[0] ?? null,
            count: $fields[1] ?? null,
            active: $fields[2] ?? null,
            tags: $fields[3] ?? null,
            scores: $fields[4] ?? null
        );
    }
}
