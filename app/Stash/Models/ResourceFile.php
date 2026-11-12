<?php

namespace App\Stash\Models;

use App\Stash\Enums\FileRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $resource_id
 * @property int $user_id
 * @property FileRole $role
 * @property string $disk
 * @property string $storage_key
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $checksum_sha256
 * @property string $status
 * @property string|null $error
 */
class ResourceFile extends Model
{
    use HasUlids;

    protected $fillable = [
        'resource_id', 'user_id', 'role', 'disk', 'storage_key', 'mime_type', 'extension',
        'size_bytes', 'width', 'height', 'checksum_sha256', 'status', 'error',
    ];

    protected $hidden = ['disk', 'storage_key'];

    protected function casts(): array
    {
        return [
            'role' => FileRole::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class)->withTrashed();
    }
}
