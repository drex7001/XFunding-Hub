<?php

namespace App\Policies;

use App\BankRole;
use App\BankStatus;
use App\Models\Bank;
use App\Models\BankDocument;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BankDocumentPolicy
{
    public function view(User $user, BankDocument $document): Response
    {
        $allowed = $user->active && ($user->is_platform_admin || ($user->membership?->active && $user->membership->bank_id === $document->bank_id && $document->bank->status !== BankStatus::Suspended));

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, Bank $bank): bool
    {
        return $user->hasBankRole(BankRole::Admin, $bank) && $bank->status === BankStatus::Pending;
    }
}
