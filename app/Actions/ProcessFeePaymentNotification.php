<?php

namespace App\Actions;

use App\DealStatus;
use App\FeePaymentStatus;
use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\FeePayment;
use App\Models\FundingRequest;
use App\Models\PaymentNotification;
use App\Models\PaymentReceipt;
use App\PaymentReceiptStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProcessFeePaymentNotification
{
    public function __construct(private DemoPaymentProvider $provider, private WithLockedDeal $lock, private ManageFeePayments $payments, private RecordAuditEvent $audit) {}

    public function handle(Request $request): string
    {
        $this->provider->authenticate($request);
        $data = Validator::make($request->json()->all(), [
            'event_reference' => ['required', 'uuid'], 'payment_reference' => ['required', 'string', 'max:60'],
            'deal_id' => ['required', 'ulid'], 'outcome' => ['required', Rule::in(['paid', 'failed'])],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?$/', $value) && FundingRequest::amountToMinor($value) < 1) {
                        $fail('A received payment amount must be positive.');
                    }
                }],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'charge_reference' => ['nullable', 'required_if:outcome,paid', 'uuid'],
        ])->validate();
        $digest = hash('sha256', $request->getContent());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->process($data, $digest);
            } catch (UniqueConstraintViolationException $exception) {
                $event = PaymentNotification::query()->where('provider', 'demo')->where('event_reference', $data['event_reference'])->first();
                if ($event !== null) {
                    abort_unless(hash_equals($event->payload_sha256, $digest), 409, 'Payment event contents conflict with an earlier delivery.');

                    return 'duplicate';
                }
                if ($attempt === 1) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Payment processing could not complete.');
    }

    /** @param array<string, mixed> $data */
    private function process(array $data, string $digest): string
    {
        $payment = FeePayment::query()->where('provider', 'demo')->where('provider_reference', $data['payment_reference'])->first();
        if ($payment === null) {
            return DB::transaction(fn (): string => $this->processLocked(null, null, $data, $digest), attempts: 3);
        }

        return $this->lock->handle(null, $payment->deal, function (Deal $deal) use ($payment, $data, $digest): string {
            $current = FeePayment::query()->lockForUpdate()->findOrFail($payment->id);

            return $this->processLocked($current, $deal, $data, $digest);
        });
    }

    /** @param array<string, mixed> $data */
    private function processLocked(?FeePayment $payment, ?Deal $deal, array $data, string $digest): string
    {
        $event = PaymentNotification::query()->where('provider', 'demo')->where('event_reference', $data['event_reference'])->lockForUpdate()->first();
        if ($event !== null) {
            abort_unless(hash_equals($event->payload_sha256, $digest), 409, 'Payment event contents conflict with an earlier delivery.');

            return 'duplicate';
        }
        $receipt = null;
        $reason = null;
        if ($data['outcome'] === 'paid') {
            $receipt = PaymentReceipt::query()->where('provider', 'demo')->where('charge_reference', $data['charge_reference'])->lockForUpdate()->first();
            if ($receipt !== null) {
                $same = $receipt->fee_payment_id === $payment?->id && $receipt->payment_reference === $data['payment_reference']
                    && $receipt->reported_deal_id === $data['deal_id'] && $receipt->amount_minor === FundingRequest::amountToMinor($data['amount']) && $receipt->currency === $data['currency'];
                $outcome = $same ? 'duplicate' : 'charge_conflict';
                $reason = $same ? null : 'charge_already_recorded';
            } else {
                $receipt = PaymentReceipt::create([
                    'fee_payment_id' => $payment?->id, 'provider' => 'demo', 'charge_reference' => $data['charge_reference'],
                    'payment_reference' => $data['payment_reference'], 'reported_deal_id' => $data['deal_id'],
                    'amount_minor' => FundingRequest::amountToMinor($data['amount']), 'currency' => $data['currency'], 'received_at' => now(),
                ]);
                if ($deal !== null) {
                    $this->payments->cancelIfExpired($deal);
                    $payment->refresh();
                }
                $reason = $this->declineReason($payment, $deal, $data);
                if ($reason === null) {
                    $receipt->forceFill(['status' => PaymentReceiptStatus::Verified, 'accepted_slot' => $payment->id])->save();
                    $payment->forceFill(['status' => FeePaymentStatus::Verified, 'pending_slot' => null, 'verified_at' => now(), 'failure_reason' => null])->save();
                    $deal->forceFill(['status' => DealStatus::Unlocked, 'verified_payment_id' => $payment->id, 'unlocked_at' => now()])->save();
                    $this->audit->handle(null, 'fee.payment_verified', $deal->fundingRequest, ['payment_id' => $payment->id, 'receipt_id' => $receipt->id]);
                    $this->audit->handle(null, 'deal.unlocked', $deal->fundingRequest, ['deal_id' => $deal->id]);
                    $outcome = 'verified';
                } else {
                    $receipt->forceFill(['status' => PaymentReceiptStatus::RefundReview, 'reason' => $reason])->save();
                    if ($payment !== null && $payment->status !== FeePaymentStatus::Verified) {
                        $payment->forceFill(['status' => FeePaymentStatus::RefundReview, 'pending_slot' => null, 'failure_reason' => $reason])->save();
                    }
                    $this->audit->handle(null, 'fee.refund_review', $deal?->fundingRequest ?? $receipt, ['receipt_id' => $receipt->id, 'reason' => $reason]);
                    $outcome = 'refund_review';
                }
            }
        } elseif ($payment === null) {
            $outcome = 'unmatched';
            $reason = 'unknown_reference';
        } elseif ($deal->id !== $data['deal_id']) {
            $outcome = 'ignored';
            $reason = 'reference_deal_mismatch';
        } else {
            $this->payments->cancelIfExpired($deal);
            $payment->refresh();
            if ($payment->status === FeePaymentStatus::Pending) {
                $payment->forceFill(['status' => FeePaymentStatus::Failed, 'pending_slot' => null, 'failure_reason' => 'provider_failed'])->save();
                $this->audit->handle(null, 'fee.payment_failed', $deal->fundingRequest, ['payment_id' => $payment->id]);
                $outcome = 'failed';
            } else {
                $outcome = 'ignored';
                $reason = 'payment_already_closed';
            }
        }
        PaymentNotification::create([
            'provider' => 'demo', 'event_reference' => $data['event_reference'], 'payload_sha256' => $digest,
            'payment_reference' => $data['payment_reference'], 'reported_deal_id' => $data['deal_id'],
            'fee_payment_id' => $payment?->id, 'payment_receipt_id' => $receipt?->id, 'outcome' => $outcome, 'reason' => $reason,
        ]);

        return $outcome;
    }

    /** @param array<string, mixed> $data */
    private function declineReason(?FeePayment $payment, ?Deal $deal, array $data): ?string
    {
        return match (true) {
            $payment === null || $deal === null => 'unknown_reference',
            $deal->id !== $data['deal_id'] => 'reference_deal_mismatch',
            $payment->amount_minor !== FundingRequest::amountToMinor($data['amount']) => 'amount_mismatch',
            $payment->currency !== $data['currency'] => 'currency_mismatch',
            $deal->currentStatus() === DealStatus::Cancelled => 'fee_deadline_or_closed_deal',
            $deal->status === DealStatus::Unlocked => 'additional_charge',
            $deal->status !== DealStatus::AwaitingFee || $payment->status !== FeePaymentStatus::Pending => 'payment_already_closed',
            $deal->fundingRequest->status !== FundingRequestStatus::Selected => 'request_no_longer_selected',
            ! $deal->hasFeeQuote() || $payment->amount_minor !== $deal->fee_amount_minor || $payment->currency !== $deal->fee_currency => 'fee_quote_mismatch',
            ! $deal->banksEligible() => 'institution_ineligible',
            $deal->pendingInstructionChange !== null => 'instruction_change_pending',
            ! $deal->hasApprovedInstructions() => 'approved_instructions_changed',
            default => null,
        };
    }
}
