<?php

namespace App\Policies;

use App\BankRole;
use App\Models\Deal;
use App\Models\FeePayment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FeePaymentPolicy
{
    public function create(User $user, Deal $deal): bool
    {
        return $user->hasVerifiedBankAccess() && $user->membership->bank_id === $deal->borrower_bank_id
            && $user->membership->bank->can_borrow && $user->hasBankRole(BankRole::Approver);
    }

    public function view(User $user, FeePayment $payment): Response
    {
        return $user->hasVerifiedBankAccess() && $user->membership->bank_id === $payment->bank_id ? Response::allow() : Response::denyAsNotFound();
    }
}
