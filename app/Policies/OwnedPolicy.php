<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that belong to one user. Anyone else gets a 404, never a 403,
 * so the existence of another user's record is not revealed.
 */
abstract class OwnedPolicy
{
    public function view(User $user, Model $record): Response
    {
        return $this->owns($user, $record);
    }

    public function update(User $user, Model $record): Response
    {
        return $this->owns($user, $record);
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->owns($user, $record);
    }

    private function owns(User $user, Model $record): Response
    {
        return $user->id === $record->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
