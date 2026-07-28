<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'paymentId' => $this->fee_payment_id, 'dealId' => $this->feePayment?->deal_id,
            'provider' => $this->provider, 'reference' => $this->payment_reference, 'chargeReference' => $this->charge_reference,
            'amount' => $this->amount, 'currency' => $this->currency,
            'expectedAmount' => $this->feePayment?->amount, 'expectedCurrency' => $this->feePayment?->currency,
            'status' => $this->status->value, 'reason' => $this->reason,
            'refundReference' => $this->refund_reference, 'reviewReason' => $this->review_reason,
            'receivedAt' => $this->received_at->toIso8601String(), 'refundedAt' => $this->refunded_at?->toIso8601String(),
        ];
    }
}
