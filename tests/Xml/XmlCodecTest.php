<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml;

use PHPUnit\Framework\TestCase;
use Smithy\Xml\{
    XmlCodec,
    XmlDeserializer,
    XmlSerializer
};
use Smithy\Tests\Xml\Utils\XmlNestedStructFixture;

final class XmlCodecTest extends TestCase
{
    public function testCreateSerializerReusesInstanceAndResetsBuffer(): void
    {
        $codec = new XmlCodec();

        $first = $codec->createSerializer();
        self::assertInstanceOf(XmlSerializer::class, $first);

        $first->serializeStruct(
            XmlNestedStructFixture::schema(),
            new XmlNestedStructFixture('alpha'),
            0
        );
        self::assertSame(
            '<NestedStruct><value>alpha</value></NestedStruct>',
            $first->flush()
        );

        $second = $codec->createSerializer();
        self::assertSame($first, $second);

        $second->serializeStruct(
            XmlNestedStructFixture::schema(),
            new XmlNestedStructFixture('beta'),
            0
        );
        self::assertSame(
            '<NestedStruct><value>beta</value></NestedStruct>',
            $second->flush()
        );
    }

    public function testCreateDeserializerDescendsThroughConfiguredWrappers(): void
    {
        $codec = new XmlCodec(['Response']);
        $deserializer = $codec->createDeserializer(
            '<Envelope><Response><NestedStruct><value>alpha</value>'
            . '</NestedStruct></Response></Envelope>',
            ['NestedStruct']
        );

        self::assertInstanceOf(XmlDeserializer::class, $deserializer);

        $members = [null];
        $deserializer->deserialize(XmlNestedStructFixture::schema(), $members);
        $output = XmlNestedStructFixture::fromMembers($members);

        self::assertSame('alpha', $output->value);
    }

    public function testCreateDeserializerWrapsParseFailures(): void
    {
        $codec = new XmlCodec();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse XML:');

        $codec->createDeserializer('<broken');
    }
}
