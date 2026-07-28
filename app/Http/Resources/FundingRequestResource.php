<?php

namespace App\Http\Resources;

use App\FundingRequestStatus;
use App\Models\FundingDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class FundingRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->borrower_profile ?? [
            'name' => $this->bank->name, 'country_code' => $this->bank->country_code,
            'registration_number' => $this->bank->registration_number, 'background' => $this->borrower_background,
        ];
        if ($this->borrower_profile === null && $this->pendingApproval?->kind === 'publish' && $this->pendingApproval->bank_profile !== null) {
            $profile = $this->pendingApproval->bank_profile + ['background' => $this->borrower_background];
        }
        $data = [
            'id' => $this->id, 'title' => $this->title, 'amount' => $this->amount, 'currency' => $this->currency,
            'tenorDays' => $this->tenor_days, 'settlementDate' => $this->settlement_date->toDateString(),
            'biddingDeadline' => $this->bidding_deadline->toIso8601String(), 'dayCount' => $this->day_count,
            'rateBasis' => $this->rate_basis, 'status' => $this->currentStatus()->value, 'publishedAt' => $this->published_at?->toIso8601String(),
            'borrower' => ['name' => $profile['name'], 'countryCode' => $profile['country_code'], 'registrationNumber' => $profile['registration_number'], 'background' => $profile['background']],
            'documents' => $this->documents->map(fn (FundingDocument $document): array => [
                'id' => $document->id, 'name' => $document->original_name, 'mimeType' => $document->mime_type,
                'size' => $document->size, 'sha256' => $document->sha256, 'downloadUrl' => route('funding.documents.show', $document),
            ])->all(),
        ];
        if ($request->user()->membership?->bank_id === $this->bank_id) {
            $pending = $this->pendingApproval;
            $data['version'] = $this->version;
            $data['pendingApproval'] = $pending ? ['id' => $pending->id, 'kind' => $pending->kind, 'expectedVersion' => $pending->expected_version, 'proposalReason' => $pending->proposal_reason] : null;
            $data['can'] = [
                'update' => Gate::allows('update', $this->resource),
                'submit' => Gate::allows('submit', $this->resource) && $this->currentStatus() === FundingRequestStatus::Draft && $pending === null && $this->documents->isNotEmpty() && $this->bidding_deadline->isFuture(),
                'proposeCancellation' => Gate::allows('proposeCancellation', $this->resource) && in_array($this->currentStatus(), [FundingRequestStatus::Draft, FundingRequestStatus::Open], true) && $pending === null && $this->pendingSelection === null,
                'review' => Gate::allows('review', $this->resource) && $this->currentStatus() !== FundingRequestStatus::Expired,
            ];
        }

        return $data;
    }
}
