<?php

declare(strict_types=1);

namespace Smithy\Tests\Xml;

use PHPUnit\Framework\TestCase;
use Smithy\Tests\Xml\Utils\{
    XmlIntEnumFixture,
    XmlNestedStructFixture,
    XmlSampleStructFixture,
    XmlSampleUnionFixture,
    XmlStringEnumFixture
};
use Smithy\Xml\XmlDeserializer;

final class XmlDeserializerTest extends TestCase
{
    public function testDeserializesScalarMembersAndEnums(): void
    {
        $deserializer = $this->createDeserializer(
            '<SampleStruct xmlns="urn:test" id="abc"><name>alpha</name>'
            . '<notes xmlns:n="urn:notes">memo</notes><count>3</count>'
            . '<active>true</active><ratio>Infinity</ratio><data>AQI='
            . '</data><created>100</created><status>open</status><code>'
            . '7</code></SampleStruct>'
        );
        $members = \array_fill(
            0,
            XmlSampleStructFixture::schema()->getMemberCount(),
            null
        );

        $deserializer->deserialize(XmlSampleStructFixture::schema(), $members);
        $output = XmlSampleStructFixture::fromMembers($members);

        self::assertSame('abc', $output->id);
        self::assertSame('alpha', $output->name);
        self::assertSame('memo', $output->notes);
        self::assertSame(3, $output->count);
        self::assertTrue($output->active);
        self::assertTrue(\is_infinite($output->ratio));
        self::assertSame("\x01\x02", $output->data);
        self::assertSame(100.0, $output->created);
        self::assertInstanceOf(XmlStringEnumFixture::class, $output->status);
        self::assertSame('open', $output->status->getValue());
        self::assertInstanceOf(XmlIntEnumFixture::class, $output->code);
        self::assertSame(7, $output->code->getValue());
    }

    public function testDeserializesCollectionsNestedStructsAndUnion(): void
    {
        $deserializer = $this->createDeserializer(
            '<SampleStruct xmlns="urn:test"><tags><member>one</member>'
            . '<member>two</member></tags><tag>flat-one</tag><tag>'
            . 'flat-two</tag><scores><entry><key>a</key><value>1</value>'
            . '</entry><entry><key>b</key><value>2</value></entry>'
            . '</scores><scoreEntry><key>x</key><value>ex</value>'
            . '</scoreEntry><scoreEntry><key>y</key><value>why</value>'
            . '</scoreEntry><child><value>kid</value></child><choice>'
            . '<count>2</count></choice><statusHistory><member>open</member>'
            . '<member>closed</member></statusHistory><milestones><entry>'
            . '<key>start</key><value>100</value></entry><entry><key>end'
            . '</key><value>200</value></entry></milestones><codeHistory>'
            . '<member>7</member><member>9</member></codeHistory>'
            . '<checkpoints><member>100</member><member>200</member>'
            . '</checkpoints></SampleStruct>'
        );
        $members = \array_fill(
            0,
            XmlSampleStructFixture::schema()->getMemberCount(),
            null
        );

        $deserializer->deserialize(XmlSampleStructFixture::schema(), $members);
        $output = XmlSampleStructFixture::fromMembers($members);

        self::assertSame(['one', 'two'], $output->tags);
        self::assertSame(['flat-one', 'flat-two'], $output->flatTags);
        self::assertSame(['a' => 1, 'b' => 2], $output->scores);
        self::assertSame(['x' => 'ex', 'y' => 'why'], $output->flatScores);
        self::assertInstanceOf(XmlNestedStructFixture::class, $output->child);
        self::assertSame('kid', $output->child->value);
        self::assertInstanceOf(XmlSampleUnionFixture::class, $output->choice);
        self::assertSame('count', $output->choice->tag());
        self::assertSame(2, $output->choice->value());
        self::assertInstanceOf(
            XmlStringEnumFixture::class,
            $output->statusHistory[0]
        );
        self::assertSame('open', $output->statusHistory[0]->getValue());
        self::assertSame('closed', $output->statusHistory[1]->getValue());
        self::assertSame(
            ['start' => 100.0, 'end' => 200.0],
            $output->milestones
        );
        self::assertInstanceOf(
            XmlIntEnumFixture::class,
            $output->codeHistory[0]
        );
        self::assertSame(7, $output->codeHistory[0]->getValue());
        self::assertSame(9, $output->codeHistory[1]->getValue());
        self::assertSame([100.0, 200.0], $output->checkpoints);
    }

    public function testDeserializesKnownRootUnionVariant(): void
    {
        $deserializer = $this->createDeserializer(
            '<SampleUnion><count>2</count></SampleUnion>'
        );
        $members = [null];

        $deserializer->deserialize(XmlSampleUnionFixture::schema(), $members);

        self::assertInstanceOf(XmlSampleUnionFixture::class, $members[0]);
        self::assertSame('count', $members[0]->tag());
        self::assertSame(2, $members[0]->value());
    }

    public function testDeserializesUnknownRootUnionVariant(): void
    {
        $deserializer = $this->createDeserializer(
            '<SampleUnion><mystery>zzz</mystery></SampleUnion>'
        );
        $members = [null];

        $deserializer->deserialize(XmlSampleUnionFixture::schema(), $members);

        self::assertInstanceOf(XmlSampleUnionFixture::class, $members[0]);
        self::assertSame('mystery', $members[0]->tag());
        self::assertSame('zzz', $members[0]->value());
    }

    private function createDeserializer(string $xml): XmlDeserializer
    {
        return new XmlDeserializer(new \SimpleXMLElement($xml));
    }
}
