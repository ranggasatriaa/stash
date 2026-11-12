<?php

namespace App\Models;

use App\Stash\Models\Project;
use App\Stash\Models\Resource;
use App\Stash\Models\SavedView;
use App\Stash\Models\Tag;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/**
 * The library owner. Accounts are created only with `php artisan stash:create-owner`.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string $timezone
 * @property array<string, mixed> $preferences
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'preferences'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasApiTokens<PersonalAccessToken|TransientToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'timezone' => 'UTC',
        'preferences' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    /** @return HasMany<Resource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<SavedView, $this> */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences ?? [], $key, $default);
    }
}
