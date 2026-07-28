<?php

namespace App\Console\Commands;

use App\Actions\ManageFundingRequests;
use App\FundingRequestStatus;
use App\Models\FundingRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ExpireFundingRequests extends Command
{
    protected $signature = 'funding:expire';

    protected $description = 'Expire funding requests whose bidding deadline has passed';

    public function handle(ManageFundingRequests $action): int
    {
        $expired = 0;
        FundingRequest::query()->whereIn('status', [FundingRequestStatus::Open->value, FundingRequestStatus::PendingApproval->value])
            ->where('bidding_deadline', '<=', now())->chunkById(100, function (Collection $requests) use ($action, &$expired): void {
                foreach ($requests as $request) {
                    if ($action->expire($request)) {
                        $expired++;
                    }
                }
            });
        $this->info("Expired {$expired} funding request(s).");

        return self::SUCCESS;
    }
}
