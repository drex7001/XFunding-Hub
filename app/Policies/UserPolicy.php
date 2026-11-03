<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function managePlatformAccounts(User $user): bool
    {
        return $user->active && $user->is_platform_admin;
    }

    /**
     * Refusing self-changes also guarantees that one active platform account always remains.
     */
    public function changePlatformAccountStatus(User $user, User $account): Response
    {
        if (! $this->managePlatformAccounts($user) || ! $account->is_platform_admin) {
            return Response::denyAsNotFound();
        }

        return $account->id === $user->id
            ? Response::deny('You cannot change your own platform account.')
            : Response::allow();
    }
}
