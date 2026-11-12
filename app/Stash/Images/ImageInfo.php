<?php

namespace App\Stash\Images;

final class ImageInfo
{
    public function __construct(
        public readonly string $mime,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
        public readonly int $size,
        public readonly string $sha256,
    ) {}
}
