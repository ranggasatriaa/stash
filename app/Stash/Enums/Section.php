<?php

namespace App\Stash\Enums;

/**
 * Navigation sections. Sections are overlapping views over the same
 * resources, never separate copies.
 */
enum Section: string
{
    case All = 'all';
    case Inbox = 'inbox';
    case Bookmarks = 'bookmarks';
    case Screenshots = 'screenshots';
    case ReadLater = 'read-later';
    case Archive = 'archive';
    case Trash = 'trash';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All saved',
            self::Inbox => 'Inbox',
            self::Bookmarks => 'Bookmarks',
            self::Screenshots => 'Screenshots',
            self::ReadLater => 'Read later',
            self::Archive => 'Archive',
            self::Trash => 'Trash',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
