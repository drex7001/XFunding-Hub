<?php

namespace App\Actions;

use App\BankRole;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use App\Notifications\DealActivityNotification;
use Illuminate\Database\Eloquent\Builder;

class RecordDealActivity
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(?User $actor, Deal $deal, string $kind, ?int $bankId = null): DealEvent
    {
        $side = $bankId === null ? null : ($bankId === $deal->borrower_bank_id ? 'borrower' : 'lender');
        $event = DealEvent::create(['deal_id' => $deal->id, 'kind' => $kind, 'side' => $side]);
        $this->audit->handle($actor, 'deal.'.$kind, $deal, ['deal_id' => $deal->id, 'side' => $side]);
        $roles = $kind === 'confirmation.proposed' ? [BankRole::Approver->value]
            : ($kind === 'confirmation.rejected' ? [BankRole::Maker->value] : array_column(BankRole::cases(), 'value'));
        $banks = in_array($kind, ['confirmation.proposed', 'confirmation.rejected'], true)
            ? [$bankId] : [$deal->borrower_bank_id, $deal->lender_bank_id];
        $recipients = User::query()->where('active', true)->where('is_platform_admin', false)
            ->whereHas('membership', fn (Builder $query): Builder => $query->where('active', true)->whereIn('bank_id', $banks))
            ->with('membership.bank')->get();
        foreach ($recipients as $recipient) {
            if ($recipient->id !== $actor?->id && $recipient->hasVerifiedBankAccess()
                && array_intersect($roles, $recipient->membership->roles) !== []) {
                $recipient->notify(new DealActivityNotification($deal->id, $recipient->membership->bank_id, $kind, $side, $roles));
            }
        }

        return $event;
    }

    public static function label(string $kind, ?string $side = null): string
    {
        $institution = $side === 'borrower' ? 'Borrower' : 'Lender';

        return match ($kind) {
            'selected' => 'Offer selected; terms reserved.',
            'fee.quoted' => 'Platform fee quoted.',
            'fee.verified' => 'Platform fee verified; treasury disclosure enabled.',
            'cancelled' => 'Fee reservation closed.',
            'instructions.updated' => 'Settlement instructions independently updated.',
            'confirmation.proposed' => $institution.' confirmation awaits independent review.',
            'confirmation.approved' => $institution.' settlement confirmation approved.',
            'confirmation.rejected' => $institution.' settlement confirmation rejected.',
            'confirmations.superseded' => 'Prior confirmations superseded by an instruction update.',
            'dispute.opened' => 'Settlement dispute opened; operations review required.',
            'dispute.resolved' => 'Settlement dispute resolution recorded.',
            'settled' => 'Both institutions confirmed settlement.',
            'closed_unsettled' => 'Deal closed without both approved confirmations.',
            default => 'Deal activity recorded.',
        };
    }
}
