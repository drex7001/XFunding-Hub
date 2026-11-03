<?php

namespace App\Actions;

use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\OfferStatus;

class DescribeWorkflowProgress
{
    /** @return array<int, array{label: string, state: string}> */
    public function funding(FundingRequest $request): array
    {
        $labels = ['Draft', 'Independent approval', 'Open for offers', 'Offer selected', 'Platform fee', 'Settlement'];
        $deal = $request->deal;
        $reached = match (true) {
            $deal?->status === DealStatus::AwaitingFee => 4,
            $deal !== null => 5,
            $request->published_at !== null => 2,
            $request->status === FundingRequestStatus::PendingApproval => 1,
            default => 0,
        };

        return match ($request->currentStatus()) {
            FundingRequestStatus::Settled => $this->steps($labels, count($labels)),
            FundingRequestStatus::Expired,
            FundingRequestStatus::Cancelled => $this->steps($labels, $reached, stopped: true),
            default => $this->steps($labels, $reached),
        };
    }

    /** @return array<int, array{label: string, state: string}> */
    public function deal(Deal $deal): array
    {
        $labels = ['Offer selected', 'Platform fee verified', 'Both banks confirm', 'Settled'];

        return match ($deal->currentStatus()) {
            DealStatus::AwaitingFee => $this->steps($labels, 1),
            DealStatus::Unlocked, DealStatus::Disputed => $this->steps($labels, 2),
            DealStatus::Settled => $this->steps($labels, count($labels)),
            DealStatus::Cancelled => $this->steps($labels, 1, stopped: true),
            DealStatus::ClosedUnsettled => $this->steps($labels, 2, stopped: true),
        };
    }

    /** @return array<int, array{label: string, state: string}> */
    public function offer(Offer $offer): array
    {
        $labels = ['Draft', 'Independent approval', 'Active', 'Selected'];

        return match ($offer->currentStatus()) {
            OfferStatus::Draft => $this->steps($labels, 0),
            OfferStatus::PendingApproval => $this->steps($labels, 1),
            OfferStatus::Active => $this->steps($labels, 2),
            OfferStatus::Selected => $this->steps($labels, count($labels)),
            default => $this->steps($labels, $offer->activated_at !== null ? 2 : 0, stopped: true),
        };
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, array{label: string, state: string}>
     */
    private function steps(array $labels, int $reached, bool $stopped = false): array
    {
        return array_map(fn (string $label, int $index): array => ['label' => $label, 'state' => match (true) {
            $index < $reached => 'done',
            $index === $reached => $stopped ? 'stopped' : 'current',
            default => 'upcoming',
        }], $labels, array_keys($labels));
    }
}
