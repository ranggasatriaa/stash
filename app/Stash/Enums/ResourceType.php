<?php

namespace App\Stash\Enums;

enum ResourceType: string
{
    case Link = 'link';
    case Image = 'image';
    case Article = 'article';
    case Video = 'video';

    public function requiresUrl(): bool
    {
        return ! $this->isUpload();
    }

    /** Resources whose content is an uploaded file rather than a source URL. */
    public function isUpload(): bool
    {
        return $this === self::Image || $this === self::Video;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
