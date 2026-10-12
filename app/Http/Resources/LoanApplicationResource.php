<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanApplicationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'applicationId' => $this->resource->getRouteKey(),
            'customerId' => $this->customer_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'termMonths' => $this->term_months,
            'status' => (string) $this->status,
        ];
    }
}
