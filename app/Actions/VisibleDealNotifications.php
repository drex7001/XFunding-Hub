<?php

namespace App\Actions;

use App\Models\Deal;
use App\Models\User;
use App\Notifications\DealActivityNotification;
use Illuminate\Database\Eloquent\Builder;

class VisibleDealNotifications
{
    /**
     * Notifications stay limited to the recipient's current bank, roles and deal relationships.
     */
    public function for(User $user): Builder
    {
        $bankId = $user->membership->bank_id;
        $roles = $user->membership->roles;

        return $user->notifications()->getQuery()->where('type', DealActivityNotification::class)
            ->where('data->bankId', $bankId)
            ->whereIn(
                'data->dealId',
                Deal::query()
                    ->select('id')
                    ->where(
                        fn (Builder $query): Builder => $query->where('borrower_bank_id', $bankId)
                            ->orWhere('lender_bank_id', $bankId)
                    ),
            )
            ->where(function (Builder $query) use ($roles): void {
                foreach ($roles as $role) {
                    $query->orWhereJsonContains('data->roles', $role);
                }
            });
    }
}
