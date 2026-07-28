<?php

namespace App\Actions;

use App\DealStatus;
use App\Models\Deal;
use App\Models\FundingRequest;

class QuotePlatformFee
{
    public function __construct(private DemoPaymentProvider $provider, private WithLockedDeal $lock, private RecordAuditEvent $audit) {}

    public function handle(Deal $deal): bool
    {
        return $this->lock->handle(null, $deal, fn (Deal $current): bool => $this->apply($current));
    }

    public function apply(Deal $deal): bool
    {
        if (! $this->provider->enabled() || $deal->status !== DealStatus::AwaitingFee || $deal->fee_amount_minor !== null) {
            return false;
        }
        $bps = config('payments.fee_basis_points');
        $taxBps = config('payments.tax_basis_points');
        $hours = config('payments.payment_window_hours');
        if (! is_int($bps) || $bps < 1 || $bps > 10000 || ! is_int($taxBps) || $taxBps < 0 || $taxBps > 10000 || ! is_int($hours) || $hours < 1 || $hours > 720) {
            throw new \LogicException('Invalid demo fee policy.');
        }
        $base = intdiv(FundingRequest::amountToMinor($deal->terms['amount']) * $bps + 9999, 10000);
        $tax = intdiv($base * $taxBps + 9999, 10000);
        $deadline = $deal->created_at->toImmutable()->addHours($hours)->min($deal->offer_valid_until);
        $deal->forceFill([
            'fee_amount_minor' => $base + $tax, 'fee_currency' => $deal->terms['currency'],
            'fee_policy' => ['version' => config('payments.policy_version'), 'mode' => 'demo', 'basis' => 'principal', 'basisPoints' => $bps, 'taxBasisPoints' => $taxBps, 'baseMinor' => $base, 'taxMinor' => $tax, 'windowHours' => $hours],
            'fee_quoted_at' => now(), 'fee_deadline' => $deadline,
        ])->save();
        $this->audit->handle(null, 'fee.quoted', $deal->fundingRequest, ['deal_id' => $deal->id]);

        return true;
    }
}
