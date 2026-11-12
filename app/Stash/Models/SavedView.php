<?php

namespace App\Stash\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string $section
 * @property array<string, mixed> $filters
 * @property string $sort
 * @property int $position
 */
class SavedView extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'section', 'filters', 'sort', 'position'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'position' => 'integer'];
    }
}
