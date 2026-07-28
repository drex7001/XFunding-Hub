<?php

namespace App\Actions;

use App\DealStatus;
use App\FeePaymentStatus;
use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\DealInstructionChange;
use App\Models\FeePayment;
use App\Models\Offer;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\OfferStatus;
use App\PaymentReceiptStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ManageFeePayments
{
    public function __construct(private WithLockedDeal $lock, private DemoPaymentProvider $provider, private RecordAuditEvent $audit) {}

    public function initiate(User $actor, Deal $deal): FeePayment
    {
        $this->provider->assertEnabled();
        Gate::forUser($actor)->authorize('view', $deal);
        Gate::forUser($actor)->authorize('create', [FeePayment::class, $deal]);
        $payment = $this->lock->handle($actor, $deal, function (Deal $current) use ($actor): ?FeePayment {
            Gate::forUser($actor)->authorize('create', [FeePayment::class, $current]);
            if ($this->cancelIfExpired($current)) {
                return null;
            }
            abort_unless($current->currentStatus() === DealStatus::AwaitingFee && $current->hasFeeQuote(), 409, 'This deal is not awaiting a payable fee quote.');
            abort_unless($current->banksEligible() && $current->hasApprovedInstructions(), 409, 'Review the institutions and approved instructions before payment.');
            abort_unless($current->pendingInstructionChange === null, 409, 'Resolve the pending instruction change before payment.');
            abort_if($current->receipts()->where('payment_receipts.status', PaymentReceiptStatus::RefundReview)->exists(), 409, 'Resolve the outstanding payment review before retrying.');
            $pending = $current->feePayments()->where('status', FeePaymentStatus::Pending)->first();
            if ($pending !== null) {
                return $pending;
            }
            $payment = FeePayment::create([
                'deal_id' => $current->id, 'bank_id' => $current->borrower_bank_id, 'initiated_by' => $actor->id,
                'provider' => 'demo', 'provider_reference' => 'XFP-'.Str::uuid(),
                'amount_minor' => $current->fee_amount_minor, 'currency' => $current->fee_currency, 'pending_slot' => $current->id,
            ]);
            $this->audit->handle($actor, 'fee.payment_initiated', $current->fundingRequest, ['payment_id' => $payment->id]);

            return $payment->refresh();
        });
        abort_unless($payment !== null, 409, 'The fee payment deadline has passed.');

        return $payment;
    }

    public function expire(Deal $deal): bool
    {
        return $this->lock->handle(null, $deal, fn (Deal $current): bool => $this->cancelIfExpired($current));
    }

    public function cancelIfExpired(Deal $deal): bool
    {
        if ($deal->status !== DealStatus::AwaitingFee || $deal->currentStatus() !== DealStatus::Cancelled) {
            return false;
        }
        $deal->forceFill(['status' => DealStatus::Cancelled, 'cancelled_at' => now()])->save();
        $deal->fundingRequest->forceFill(['status' => FundingRequestStatus::Cancelled, 'cancelled_at' => now()])->save();
        Offer::query()->whereKey($deal->offer_id)->where('status', OfferStatus::Selected)->update(['status' => OfferStatus::Expired, 'active_slot' => null]);
        $deal->feePayments()->where('status', FeePaymentStatus::Pending)->update(['status' => FeePaymentStatus::Failed, 'pending_slot' => null, 'failure_reason' => 'fee_deadline']);
        DealInstructionChange::query()->where('deal_id', $deal->id)->where('status', 'pending')->update(['status' => 'superseded', 'pending_slot' => null]);
        $this->audit->handle(null, 'fee.deadline_expired', $deal->fundingRequest, ['deal_id' => $deal->id]);

        return true;
    }

    public function recordRefund(User $actor, PaymentReceipt $receipt, string $reference, string $reason): PaymentReceipt
    {
        $this->provider->assertEnabled();
        Gate::forUser($actor)->authorize('refund', $receipt);
        $callback = function (?Deal $deal = null) use ($actor, $receipt, $reference, $reason): PaymentReceipt {
            $current = PaymentReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $authVersion = $actor->auth_version;
            $actor = $actor->fresh(['membership.bank']);
            abort_unless($actor !== null && $actor->auth_version === $authVersion, 401, 'Please sign in again.');
            Gate::forUser($actor)->authorize('refund', $current);
            if ($current->status === PaymentReceiptStatus::Refunded) {
                abort_unless($current->refund_reference === $reference, 409, 'A different refund is already recorded.');

                return $current;
            }
            abort_unless($current->status === PaymentReceiptStatus::RefundReview, 409, 'Only receipts awaiting refund review can be refunded.');
            $current->forceFill(['status' => PaymentReceiptStatus::Refunded, 'reviewed_by' => $actor->id, 'refund_reference' => $reference, 'review_reason' => $reason, 'refunded_at' => now()])->save();
            if ($current->fee_payment_id !== null) {
                $payment = FeePayment::query()->lockForUpdate()->findOrFail($current->fee_payment_id);
                if ($payment->status !== FeePaymentStatus::Verified && ! $payment->receipts()->where('status', PaymentReceiptStatus::RefundReview)->exists()) {
                    $payment->forceFill(['status' => FeePaymentStatus::Refunded, 'pending_slot' => null])->save();
                }
            }
            $this->audit->handle($actor, 'fee.refund_recorded', $deal?->fundingRequest ?? $current, ['receipt_id' => $current->id, 'reason' => $reason]);

            return $current;
        };
        try {
            return $receipt->feePayment !== null
                ? $this->lock->handle($actor, $receipt->feePayment->deal, $callback)
                : DB::transaction(fn (): PaymentReceipt => $callback(null), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This refund reference has already been used.');
        }
    }
}
