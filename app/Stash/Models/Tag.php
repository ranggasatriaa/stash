<?php

namespace App\Stash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string $normalized_name
 * @property string|null $color
 */
class Tag extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'normalized_name', 'color'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Resource, $this> */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'resource_tag');
    }
}
