<?php

namespace App\Http\Resources;

use App\ApprovalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class SettlementConfirmationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'status' => $this->status->value,
            'proposedAt' => $this->created_at->toIso8601String(),
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'canReview' => $this->status === ApprovalStatus::Pending && Gate::allows('review', $this->resource),
        ];
        if (Gate::allows('viewDetails', $this->resource)) {
            $data += [
                'settlementAt' => $this->settlement_at->toIso8601String(),
                'reference' => $this->reference,
                'note' => $this->note,
                'reviewReason' => $this->review_reason,
            ];
        }

        return $data;
    }
}
