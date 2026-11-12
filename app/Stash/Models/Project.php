<?php

namespace App\Stash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property string|null $color
 * @property string|null $icon
 */
class Project extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'description', 'color', 'icon'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Resource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }
}
