<?php

declare(strict_types=1);

namespace Smithy\Json;

/**
 * Wrap big-number values for JSON serialization.
 *
 * Marks arbitrary-precision numbers so `flush()` can emit them as JSON
 * number tokens instead of strings.
 */
final class BigNumberToken implements \JsonSerializable
{
    public const ENCODED_PREFIX = '__SMITHY_BIGNUM__';

    /**
     * Create a wrapped big-number token.
     */
    public function __construct(public readonly string $digits) {}

    /**
     * Return the sentinel-prefixed digit string for `json_encode()`.
     */
    public function jsonSerialize(): string
    {
        return self::ENCODED_PREFIX . $this->digits;
    }
}
