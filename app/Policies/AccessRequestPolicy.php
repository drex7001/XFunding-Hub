<?php

namespace App\Policies;

use App\BankRole;
use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AccessRequestPolicy
{
    public function view(User $user, AccessRequest $request): Response
    {
        return $user->active && ($user->is_platform_admin || $user->hasBankRole(BankRole::Admin, $request->bank))
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function review(User $user, AccessRequest $request): bool
    {
        return $this->view($user, $request)->allowed()
            && $request->requested_by !== $user->id
            && $request->membership?->user_id !== $user->id;
    }
}
