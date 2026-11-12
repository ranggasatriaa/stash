<?php

namespace App\Stash\Enums;

enum ResourceType: string
{
    case Link = 'link';
    case Image = 'image';
    case Article = 'article';

    public function requiresUrl(): bool
    {
        return $this !== self::Image;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
