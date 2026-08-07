<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml\Utils;

use Smithy\Core\Serde\{
    Schema,
    SerializableStructInterface,
    ShapeSerializerInterface,
    ShapeType,
    TimestampFormat
};
use Smithy\Core\Shape\{
    BigDecimal,
    BigInteger,
    FromMembersTrait
};

final class XmlSampleStructFixture implements SerializableStructInterface
{
    use FromMembersTrait;

    private static ?Schema $SCHEMA = null;

    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?string $notes = null,
        public readonly ?int $count = null,
        public readonly ?bool $active = null,
        public readonly ?float $ratio = null,
        public readonly ?string $data = null,
        public readonly ?float $created = null,
        public readonly ?BigInteger $bigInteger = null,
        public readonly ?BigDecimal $bigDecimal = null,
        public readonly ?XmlStringEnumFixture $status = null,
        public readonly ?XmlIntEnumFixture $code = null,
        /** @var list<string>|null */
        public readonly ?array $tags = null,
        /** @var list<string>|null */
        public readonly ?array $flatTags = null,
        /** @var array<string, int>|null */
        public readonly ?array $scores = null,
        /** @var array<string, string>|null */
        public readonly ?array $flatScores = null,
        public readonly ?XmlNestedStructFixture $child = null,
        public readonly ?XmlSampleUnionFixture $choice = null,
        /** @var list<XmlStringEnumFixture>|null */
        public readonly ?array $statusHistory = null,
        /** @var array<string, float>|null */
        public readonly ?array $milestones = null,
        /** @var list<XmlIntEnumFixture>|null */
        public readonly ?array $codeHistory = null,
        /** @var list<float>|null */
        public readonly ?array $checkpoints = null
    ) {}

    public static function schema(): Schema
    {
        if (self::$SCHEMA !== null) {
            return self::$SCHEMA;
        }

        $tagMember = new Schema(
            id: 'smithy.test#SampleStruct$tags$member',
            type: ShapeType::String_,
            memberName: 'member',
            memberIndex: 0
        );
        $flatTagMember = new Schema(
            id: 'smithy.test#SampleStruct$flatTags$member',
            type: ShapeType::String_,
            memberName: 'member',
            memberIndex: 0
        );
        $scoreKey = new Schema(
            id: 'smithy.test#SampleStruct$scores$key',
            type: ShapeType::String_,
            memberName: 'key',
            memberIndex: 0
        );
        $scoreValue = new Schema(
            id: 'smithy.test#SampleStruct$scores$value',
            type: ShapeType::Integer,
            memberName: 'value',
            memberIndex: 1
        );
        $flatScoreKey = new Schema(
            id: 'smithy.test#SampleStruct$flatScores$key',
            type: ShapeType::String_,
            memberName: 'key',
            memberIndex: 0
        );
        $flatScoreValue = new Schema(
            id: 'smithy.test#SampleStruct$flatScores$value',
            type: ShapeType::String_,
            memberName: 'value',
            memberIndex: 1
        );
        $statusHistoryMember = new Schema(
            id: 'smithy.test#SampleStruct$statusHistory$member',
            type: ShapeType::Enum,
            memberName: 'member',
            memberIndex: 0,
            targetClass: XmlStringEnumFixture::class
        );
        $milestoneKey = new Schema(
            id: 'smithy.test#SampleStruct$milestones$key',
            type: ShapeType::String_,
            memberName: 'key',
            memberIndex: 0
        );
        $milestoneValue = new Schema(
            id: 'smithy.test#SampleStruct$milestones$value',
            type: ShapeType::Timestamp,
            memberName: 'value',
            memberIndex: 1,
            traits: [Schema::T_TIMESTAMP_FORMAT => 'epoch-seconds']
        );
        $codeHistoryMember = new Schema(
            id: 'smithy.test#SampleStruct$codeHistory$member',
            type: ShapeType::IntEnum,
            memberName: 'member',
            memberIndex: 0,
            targetClass: XmlIntEnumFixture::class
        );
        $checkpointMember = new Schema(
            id: 'smithy.test#SampleStruct$checkpoints$member',
            type: ShapeType::Timestamp,
            memberName: 'member',
            memberIndex: 0,
            traits: [Schema::T_TIMESTAMP_FORMAT => 'epoch-seconds']
        );

        self::$SCHEMA = new Schema(
            id: 'smithy.test#SampleStruct',
            type: ShapeType::Structure,
            targetClass: self::class,
            traits: [
                Schema::T_XML_NAMESPACE => ['uri' => 'urn:test'],
            ],
            members: [
                new Schema(
                    id: 'smithy.test#SampleStruct$id',
                    type: ShapeType::String_,
                    memberName: 'id',
                    memberIndex: 0,
                    traits: [
                        Schema::T_XML_ATTRIBUTE => true,
                        Schema::T_XML_NAME => 'id',
                    ]
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$name',
                    type: ShapeType::String_,
                    memberName: 'name',
                    memberIndex: 1
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$notes',
                    type: ShapeType::String_,
                    memberName: 'notes',
                    memberIndex: 2,
                    traits: [
                        Schema::T_XML_NAMESPACE => [
                            'uri' => 'urn:notes',
                            'prefix' => 'n',
                        ],
                    ]
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$count',
                    type: ShapeType::Integer,
                    memberName: 'count',
                    memberIndex: 3
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$active',
                    type: ShapeType::Boolean,
                    memberName: 'active',
                    memberIndex: 4
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$ratio',
                    type: ShapeType::Double,
                    memberName: 'ratio',
                    memberIndex: 5
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$data',
                    type: ShapeType::Blob,
                    memberName: 'data',
                    memberIndex: 6
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$created',
                    type: ShapeType::Timestamp,
                    memberName: 'created',
                    memberIndex: 7,
                    traits: [Schema::T_TIMESTAMP_FORMAT => 'epoch-seconds']
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$bigInteger',
                    type: ShapeType::BigInteger,
                    memberName: 'bigInteger',
                    memberIndex: 8
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$bigDecimal',
                    type: ShapeType::BigDecimal,
                    memberName: 'bigDecimal',
                    memberIndex: 9
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$status',
                    type: ShapeType::Enum,
                    memberName: 'status',
                    memberIndex: 10,
                    targetClass: XmlStringEnumFixture::class
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$code',
                    type: ShapeType::IntEnum,
                    memberName: 'code',
                    memberIndex: 11,
                    targetClass: XmlIntEnumFixture::class
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$tags',
                    type: ShapeType::List_,
                    memberName: 'tags',
                    memberIndex: 12,
                    listMember: $tagMember
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$flatTags',
                    type: ShapeType::List_,
                    memberName: 'flatTags',
                    memberIndex: 13,
                    listMember: $flatTagMember,
                    traits: [
                        Schema::T_XML_FLATTENED => true,
                        Schema::T_XML_NAME => 'tag',
                    ]
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$scores',
                    type: ShapeType::Map,
                    memberName: 'scores',
                    memberIndex: 14,
                    mapKey: $scoreKey,
                    mapValue: $scoreValue
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$flatScores',
                    type: ShapeType::Map,
                    memberName: 'flatScores',
                    memberIndex: 15,
                    mapKey: $flatScoreKey,
                    mapValue: $flatScoreValue,
                    traits: [
                        Schema::T_XML_FLATTENED => true,
                        Schema::T_XML_NAME => 'scoreEntry',
                    ]
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$child',
                    type: ShapeType::Structure,
                    memberName: 'child',
                    memberIndex: 16,
                    targetClass: XmlNestedStructFixture::class
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$choice',
                    type: ShapeType::Union,
                    memberName: 'choice',
                    memberIndex: 17,
                    targetClass: XmlSampleUnionFixture::class
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$statusHistory',
                    type: ShapeType::List_,
                    memberName: 'statusHistory',
                    memberIndex: 18,
                    listMember: $statusHistoryMember
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$milestones',
                    type: ShapeType::Map,
                    memberName: 'milestones',
                    memberIndex: 19,
                    mapKey: $milestoneKey,
                    mapValue: $milestoneValue
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$codeHistory',
                    type: ShapeType::List_,
                    memberName: 'codeHistory',
                    memberIndex: 20,
                    listMember: $codeHistoryMember
                ),
                new Schema(
                    id: 'smithy.test#SampleStruct$checkpoints',
                    type: ShapeType::List_,
                    memberName: 'checkpoints',
                    memberIndex: 21,
                    listMember: $checkpointMember
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
        $members = self::schema()->getMembersByIndex();

        if ($this->id !== null) {
            $serializer->serializeString($members[0], $this->id, 0);
        }
        if ($this->name !== null) {
            $serializer->serializeString($members[1], $this->name, 1);
        }
        if ($this->notes !== null) {
            $serializer->serializeString($members[2], $this->notes, 2);
        }
        if ($this->count !== null) {
            $serializer->serializeInteger($members[3], $this->count, 3);
        }
        if ($this->active !== null) {
            $serializer->serializeBoolean($members[4], $this->active, 4);
        }
        if ($this->ratio !== null) {
            $serializer->serializeFloat($members[5], $this->ratio, 5);
        }
        if ($this->data !== null) {
            $serializer->serializeBlob($members[6], $this->data, 6);
        }
        if ($this->created !== null) {
            $serializer->serializeTimestamp(
                $members[7],
                $this->created,
                7,
                TimestampFormat::EpochSeconds
            );
        }
        if ($this->bigInteger !== null) {
            $serializer->serializeBigInteger(
                $members[8],
                $this->bigInteger,
                8
            );
        }
        if ($this->bigDecimal !== null) {
            $serializer->serializeBigDecimal(
                $members[9],
                $this->bigDecimal,
                9
            );
        }
        if ($this->status !== null) {
            $serializer->serializeString(
                $members[10],
                (string) $this->status->getValue(),
                10
            );
        }
        if ($this->code !== null) {
            $serializer->serializeInteger(
                $members[11],
                (int) $this->code->getValue(),
                11
            );
        }
        if ($this->tags !== null) {
            $serializer->serializeList(
                $members[12],
                $this->tags,
                12,
                ShapeType::String_
            );
        }
        if ($this->flatTags !== null) {
            $serializer->serializeList(
                $members[13],
                $this->flatTags,
                13,
                ShapeType::String_
            );
        }
        if ($this->scores !== null) {
            $serializer->serializeMap($members[14], $this->scores, 14);
        }
        if ($this->flatScores !== null) {
            $serializer->serializeMap($members[15], $this->flatScores, 15);
        }
        if ($this->child !== null) {
            $serializer->serializeStruct($members[16], $this->child, 16);
        }
        if ($this->choice !== null) {
            $serializer->serializeUnion($members[17], $this->choice, 17);
        }
        if ($this->statusHistory !== null) {
            $values = \array_map(
                static fn (XmlStringEnumFixture $item): string =>
                    (string) $item->getValue(),
                $this->statusHistory
            );
            $serializer->serializeList(
                $members[18],
                $values,
                18,
                ShapeType::Enum
            );
        }
        if ($this->milestones !== null) {
            $serializer->serializeMap($members[19], $this->milestones, 19);
        }
        if ($this->codeHistory !== null) {
            $values = \array_map(
                static fn (XmlIntEnumFixture $item): int =>
                    (int) $item->getValue(),
                $this->codeHistory
            );
            $serializer->serializeList(
                $members[20],
                $values,
                20,
                ShapeType::IntEnum
            );
        }
        if ($this->checkpoints !== null) {
            $serializer->serializeList(
                $members[21],
                $this->checkpoints,
                21,
                ShapeType::Timestamp
            );
        }
    }

    public function getMemberValue(Schema $member): mixed
    {
        return match ($member->memberIndex) {
            0 => $this->id,
            1 => $this->name,
            2 => $this->notes,
            3 => $this->count,
            4 => $this->active,
            5 => $this->ratio,
            6 => $this->data,
            7 => $this->created,
            8 => $this->bigInteger,
            9 => $this->bigDecimal,
            10 => $this->status,
            11 => $this->code,
            12 => $this->tags,
            13 => $this->flatTags,
            14 => $this->scores,
            15 => $this->flatScores,
            16 => $this->child,
            17 => $this->choice,
            18 => $this->statusHistory,
            19 => $this->milestones,
            20 => $this->codeHistory,
            21 => $this->checkpoints,
            default => null,
        };
    }
}
