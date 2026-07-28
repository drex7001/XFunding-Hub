<?php

namespace App\Policies;

use App\BankRole;
use App\BankStatus;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BankPolicy
{
    public function view(User $user, Bank $bank): Response
    {
        $allowed = $user->active && ($user->is_platform_admin || ($user->membership?->active && $user->membership->bank_id === $bank->id));

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->active && $user->is_platform_admin;
    }

    public function update(User $user, Bank $bank): bool
    {
        return $user->hasBankRole(BankRole::Admin, $bank) && $bank->status !== BankStatus::Suspended;
    }

    public function manageMembers(User $user, Bank $bank): bool
    {
        return $this->update($user, $bank);
    }

    public function verify(User $user, Bank $bank): bool
    {
        return $this->create($user);
    }
}
