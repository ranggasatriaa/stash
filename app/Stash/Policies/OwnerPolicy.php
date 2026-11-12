<?php

namespace App\Stash\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Owner-only access for every owner-controlled model. Foreign records are
 * reported as "not found" (404) so their existence is not revealed. Token
 * abilities are enforced separately and can never widen this check.
 */
class OwnerPolicy
{
    public function view(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    public function update(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    public function delete(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    public function forceDelete(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    public function restore(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    private function owns(User $user, Model $model): Response
    {
        return (int) $model->getAttribute('user_id') === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
