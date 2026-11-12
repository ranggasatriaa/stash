<?php

namespace App\Stash\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $user_id
 * @property string $format json|zip
 * @property string $status uploaded|analyzing|ready|invalid|applying|completed|failed
 * @property string $disk
 * @property string $storage_key
 * @property string $original_filename
 * @property int $size_bytes
 * @property string $collision_strategy skip|duplicate
 * @property array<string, mixed>|null $preview
 * @property array<string, mixed>|null $report
 * @property string|null $error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 */
class DataImport extends Model
{
    use HasUlids;

    protected $fillable = ['format', 'status', 'disk', 'storage_key', 'original_filename', 'size_bytes', 'collision_strategy', 'preview', 'report', 'error', 'expires_at'];

    protected $hidden = ['disk', 'storage_key'];

    protected function casts(): array
    {
        return ['preview' => 'array', 'report' => 'array', 'expires_at' => 'datetime', 'size_bytes' => 'integer'];
    }
}
