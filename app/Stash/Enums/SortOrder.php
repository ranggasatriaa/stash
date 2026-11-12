<?php

namespace App\Stash\Enums;

enum SortOrder: string
{
    case Relevance = 'relevance';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Title = 'title';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
