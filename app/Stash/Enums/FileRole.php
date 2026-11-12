<?php

namespace App\Stash\Enums;

enum FileRole: string
{
    case Original = 'original';
    case Preview = 'preview';
    case Thumbnail = 'thumbnail';
}
