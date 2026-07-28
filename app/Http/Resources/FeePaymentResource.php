<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeePaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'dealId' => $this->deal_id, 'reference' => $this->provider_reference,
            'amount' => $this->amount, 'currency' => $this->currency,
            'status' => $this->status->value, 'failureReason' => $this->failure_reason,
            'verifiedAt' => $this->verified_at?->toIso8601String(), 'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
