<?php

declare(strict_types=1);

namespace Smithy\Tests\Json;

use PHPUnit\Framework\TestCase;
use Smithy\Core\Serde\{
    Schema,
    ShapeType
};
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\AbstractDocument;
use Smithy\Json\JsonSerializer;

final class JsonSerializerDocumentTest extends TestCase
{
    public function testSerializesNestedDocumentValuesAndNulls(): void
    {
        $document = AbstractDocument::fromValue([
            'foo' => 'bar',
            'items' => [true, null, 1],
            'nested' => ['value' => null]
        ]);
        self::assertNotNull($document);

        $serializer = new JsonSerializer();
        $document->serialize($serializer, 0);

        self::assertSame(
            '{"foo":"bar","items":[true,null,1],"nested":{"value":null}}',
            $serializer->flush()
        );
    }

    public function testTopLevelDocumentListWithBigIntegersUsesNumberTokens(): void
    {
        $schema = new Schema(
            id: 'smithy.test#DocumentList',
            type: ShapeType::List_,
            listMember: new Schema(
                id: 'smithy.test#DocumentList$member',
                type: ShapeType::Document
            )
        );

        $serializer = new JsonSerializer();
        $serializer->serializeList(
            $schema,
            [AbstractDocument::fromValue(new BigInteger('0001'))],
            0,
            ShapeType::Document
        );

        self::assertSame('[1]', $serializer->flush());
    }
}
