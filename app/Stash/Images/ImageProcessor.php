<?php

namespace App\Stash\Images;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

/**
 * Produces bounded, metadata-free WebP variants with EXIF orientation
 * applied. GD re-encoding never carries EXIF/GPS data; with Imagick the
 * `strip` option removes it.
 */
final class ImageProcessor
{
    /**
     * @return array{thumbnail: array{bytes: string, width: int, height: int}, preview: array{bytes: string, width: int, height: int}}
     */
    public function variants(string $sourcePath): array
    {
        $limit = (string) config('stash.images.memory_limit', '512M');
        if ($limit !== '' && self::bytes((string) ini_get('memory_limit')) < self::bytes($limit)) {
            @ini_set('memory_limit', $limit);
        }

        $quality = (int) config('stash.images.quality', 82);

        $thumb = $this->manager()->read($sourcePath);
        $thumb->scaleDown(width: (int) config('stash.images.thumbnail_width', 640), height: (int) config('stash.images.thumbnail_max_height', 1280));
        $thumbnail = ['bytes' => (string) $thumb->toWebp(quality: $quality), 'width' => $thumb->width(), 'height' => $thumb->height()];
        unset($thumb);

        $max = (int) config('stash.images.preview_max', 1600);
        $prev = $this->manager()->read($sourcePath);
        $prev->scaleDown(width: $max, height: $max);
        $preview = ['bytes' => (string) $prev->toWebp(quality: $quality), 'width' => $prev->width(), 'height' => $prev->height()];

        return ['thumbnail' => $thumbnail, 'preview' => $preview];
    }

    public function manager(): ImageManager
    {
        $driver = config('stash.images.driver') === 'imagick' && extension_loaded('imagick')
            ? new ImagickDriver
            : new GdDriver;

        return new ImageManager($driver, autoOrientation: true, decodeAnimation: false, strip: true);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
