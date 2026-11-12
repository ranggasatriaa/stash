<?php

namespace App\Stash\Support;

use Normalizer;

/**
 * Tag names are unique per owner after trimming, whitespace collapsing,
 * Unicode NFKC normalization and case folding.
 */
final class TagName
{
    public const MAX_LENGTH = 64;

    public static function clean(string $name): string
    {
        $name = Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name;
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = ltrim(trim($name), '#');

        return trim(mb_substr($name, 0, self::MAX_LENGTH));
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(self::clean($name));
    }
}
