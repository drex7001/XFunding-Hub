<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class SettlementInstructionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'version' => $this->version, 'status' => $this->status,
            'bank' => $this->bank_profile, 'details' => $this->details, 'reason' => $this->reason,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'canReview' => $this->status === 'pending' && Gate::allows('review', $this->resource),
        ];
    }
}
