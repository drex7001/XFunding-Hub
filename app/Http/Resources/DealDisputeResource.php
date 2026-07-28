<?php

namespace App\Http\Resources;

use App\BankRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealDisputeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $data = ['id' => $this->id, 'status' => $this->status, 'openedAt' => $this->created_at->toIso8601String(), 'resolvedAt' => $this->resolved_at?->toIso8601String()];
        if ($user->membership?->bank_id === $this->bank_id && ($user->hasBankRole(BankRole::Maker) || $user->hasBankRole(BankRole::Approver))) {
            $data['description'] = $this->description;
            $data['resolutionNote'] = $this->resolution_note;
        }

        return $data;
    }
}
