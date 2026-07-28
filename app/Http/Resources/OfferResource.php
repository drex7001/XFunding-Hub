<?php

namespace App\Http\Resources;

use App\FundingRequestStatus;
use App\Models\SettlementInstruction;
use App\OfferStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class OfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $open = $this->fundingRequest->currentStatus() === FundingRequestStatus::Open;
        $data = [
            'id' => $this->id, 'fundingRequestId' => $this->funding_request_id,
            'borrowerName' => $this->fundingRequest->borrower_profile['name'],
            'title' => $this->fundingRequest->title, 'amount' => $this->fundingRequest->amount,
            'currency' => $this->fundingRequest->currency, 'tenorDays' => $this->fundingRequest->tenor_days,
            'dayCount' => $this->fundingRequest->day_count, 'rateBasis' => $this->fundingRequest->rate_basis,
            'rate' => $this->rate, 'collateral' => $this->collateral, 'interestPayment' => $this->interest_payment,
            'validUntil' => $this->valid_until->toIso8601String(), 'status' => $this->currentStatus()->value,
            'version' => $this->version,
            'pendingApproval' => $this->pendingApproval ? ['id' => $this->pendingApproval->id, 'expectedVersion' => $this->pendingApproval->expected_version] : null,
            'can' => [
                'update' => Gate::allows('update', $this->resource),
                'submit' => $open && Gate::allows('submit', $this->resource) && $this->status === OfferStatus::Draft,
                'review' => $open && Gate::allows('review', $this->resource) && $this->currentStatus() === OfferStatus::PendingApproval,
                'withdraw' => $open && Gate::allows('withdraw', $this->resource) && in_array($this->currentStatus(), [OfferStatus::Draft, OfferStatus::PendingApproval, OfferStatus::Active], true),
            ],
        ];
        if (Gate::allows('viewAny', SettlementInstruction::class)) {
            $data['instructionId'] = $this->settlement_instruction_id;
            $data['instructionVersion'] = $this->settlementInstruction->version;
        }

        return $data;
    }
}
