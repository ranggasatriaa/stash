<?php

namespace App\Stash\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $user_id
 * @property string $format json|zip|obsidian
 * @property bool $include_trash
 * @property string $status queued|processing|ready|failed|expired
 * @property string|null $disk
 * @property string|null $storage_key
 * @property string|null $filename
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property array<string, mixed> $stats
 * @property string|null $error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 */
class DataExport extends Model
{
    use HasUlids;

    protected $fillable = ['format', 'include_trash', 'status', 'disk', 'storage_key', 'filename', 'size_bytes', 'checksum_sha256', 'stats', 'error', 'expires_at'];

    protected $hidden = ['disk', 'storage_key'];

    protected $attributes = ['stats' => '{}'];

    protected function casts(): array
    {
        return ['include_trash' => 'boolean', 'stats' => 'array', 'expires_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
