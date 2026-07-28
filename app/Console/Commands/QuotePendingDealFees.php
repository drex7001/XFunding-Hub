<?php

namespace App\Console\Commands;

use App\Actions\ManageFeePayments;
use App\Actions\QuotePlatformFee;
use App\DealStatus;
use App\Models\Deal;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class QuotePendingDealFees extends Command
{
    protected $signature = 'fees:quote-pending';

    protected $description = 'Prepare frozen fee quotes for selected deals that do not yet have one';

    public function handle(QuotePlatformFee $quotes, ManageFeePayments $payments): int
    {
        $count = 0;
        Deal::query()->where('status', DealStatus::AwaitingFee)->whereNull('fee_amount_minor')->chunkById(100, function (Collection $deals) use ($quotes, $payments, &$count): void {
            foreach ($deals as $deal) {
                if ($quotes->handle($deal)) {
                    $count++;
                    $payments->expire($deal->fresh());
                }
            }
        });
        $this->info("Prepared {$count} fee quote(s).");

        return self::SUCCESS;
    }
}
