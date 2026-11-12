<?php

namespace App\Stash\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $scope
 * @property string $key
 * @property string $request_hash
 * @property string $method
 * @property string $path
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property string|null $resource_ref
 * @property CarbonImmutable $expires_at
 */
class IdempotencyRecord extends Model
{
    protected $fillable = ['user_id', 'scope', 'key', 'request_hash', 'method', 'path', 'response_status', 'response_body', 'resource_ref', 'expires_at'];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'expires_at' => 'datetime', 'response_status' => 'integer'];
    }
}
