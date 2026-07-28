<?php

namespace App\Http\Resources;

use App\Actions\RecordDealActivity;
use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'message' => RecordDealActivity::label($this->kind, $this->side), 'at' => $this->created_at->toIso8601String()];
    }

    /** @return array<int, array<string, mixed>> */
    public static function milestones(Deal $deal): array
    {
        $facts = [['kind' => 'selected', 'at' => $deal->created_at->toIso8601String()]];
        foreach (['fee.quoted' => 'fee_quoted_at', 'fee.verified' => 'unlocked_at', 'cancelled' => 'cancelled_at'] as $kind => $field) {
            if ($deal->$field !== null) {
                $facts[] = ['kind' => $kind, 'at' => $deal->$field->toIso8601String()];
            }
        }
        foreach ($deal->instructionChanges as $change) {
            if ($change->status === 'approved' && $change->reviewed_at !== null) {
                $facts[] = ['kind' => 'instructions.updated', 'at' => $change->reviewed_at->toIso8601String()];
            }
        }

        return collect($facts)->map(fn (array $fact): array => $fact + ['message' => RecordDealActivity::label($fact['kind'])])->sortBy('at')->values()->all();
    }
}
