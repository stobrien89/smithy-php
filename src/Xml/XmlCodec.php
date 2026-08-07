<?php

declare(strict_types=1);

namespace Smithy\Xml;

use Smithy\Core\Serde\AbstractCodec;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Provide XML serialization and deserialization for Smithy shapes.
 *
 * Owns parser and writer creation. Reuses one {@see XmlSerializer} and
 * {@see \XMLWriter} pair per codec instance so repeated operations do not
 * pay fresh allocation cost.
 */
final class XmlCodec extends AbstractCodec
{
    /**
     * The pooled writer + serializer, or null before first use. Both
     * live for the lifetime of this codec instance.
     */
    private ?\XMLWriter $writer = null;
    private ?XmlSerializer $serializer = null;

    /**
     * Create an XML codec.
     *
     * @param list<string> $wrapperElements Top-level wrapper element names to
     *                                      skip during deserialization.
     * @param string|null  $defaultNamespace Default XML namespace URI applied
     *                                       to root elements.
     */
    public function __construct(
        private readonly array $wrapperElements = [],
        private readonly ?string $defaultNamespace = null
    ) {}

    /**
     * Create an XML serializer.
     */
    public function createSerializer(): ShapeSerializerInterface
    {
        // Lazy init once per codec instance. `openMemory()` fully resets
        // the writer's internal document buffer so subsequent calls get a
        // clean slate, so there is no need to construct a new writer each
        // time.
        if ($this->serializer === null) {
            $this->writer = new \XMLWriter();
            $this->writer->openMemory();
            $this->serializer = new XmlSerializer(
                $this->writer,
                $this->defaultNamespace
            );
        } else {
            // Same writer, fresh document. openMemory rewinds the internal
            // buffer to position 0 and clears any pending element stack,
            // effectively `writer->reset()`.
            $this->writer->openMemory();
        }

        return $this->serializer;
    }

    /**
     * Create an XML deserializer.
     *
     * @param string       $source XML text to parse.
     * @param list<string> $extraWrapperElements Additional wrapper element
     *                                           names to descend into for
     *                                           this call.
     */
    public function createDeserializer(
        string $source,
        array $extraWrapperElements = []
    ): ShapeDeserializerInterface
    {
        $useInternalErrors = \libxml_use_internal_errors(true);
        try {
            $xml = new \SimpleXMLElement(
                $source,
                LIBXML_NOBLANKS | LIBXML_NONET | LIBXML_COMPACT
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Failed to parse XML: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($useInternalErrors);
        }

        // Descend through wrapper elements. The codec-configured list
        // runs first (static per-service wrappers if any), then any
        // per-call extras (per-operation envelopes for awsQuery).
        // `isset($xml->{name})` uses SimpleXML's __isset which walks
        // the children of the current element. A match steps in.
        // a miss leaves the current element unchanged (defensive against
        // servers that omit the outer wrapper).
        foreach ($this->wrapperElements as $wrapper) {
            if (isset($xml->{$wrapper})) {
                $xml = $xml->{$wrapper};
            }
        }
        foreach ($extraWrapperElements as $wrapper) {
            if (isset($xml->{$wrapper})) {
                $xml = $xml->{$wrapper};
            }
        }

        return new XmlDeserializer($xml);
    }
}
