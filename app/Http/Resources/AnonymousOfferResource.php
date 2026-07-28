<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnonymousOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->borrower_token, 'label' => 'Offer '.$this->label_number,
            'rate' => $this->rate, 'collateral' => $this->collateral, 'interestPayment' => $this->interest_payment,
            'validUntil' => $this->valid_until->toIso8601String(),
        ];
    }
}
