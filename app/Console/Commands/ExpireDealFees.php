<?php

namespace App\Console\Commands;

use App\Actions\ManageFeePayments;
use App\DealStatus;
use App\Models\Deal;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ExpireDealFees extends Command
{
    protected $signature = 'deals:expire-fees';

    protected $description = 'Close selected deals whose fee or offer reservation deadline has passed';

    public function handle(ManageFeePayments $action): int
    {
        $count = 0;
        Deal::query()->where('status', DealStatus::AwaitingFee)
            ->where(fn (Builder $query): Builder => $query->where('fee_deadline', '<=', now())->orWhere('offer_valid_until', '<=', now()))
            ->chunkById(100, function (Collection $deals) use ($action, &$count): void {
                foreach ($deals as $deal) {
                    if ($action->expire($deal)) {
                        $count++;
                    }
                }
            });
        $this->info("Expired {$count} fee reservation(s).");

        return self::SUCCESS;
    }
}
