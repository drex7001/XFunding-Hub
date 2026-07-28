<?php

namespace App\Console\Commands;

use App\Actions\ManageOffers;
use App\Models\Offer;
use App\OfferStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ExpireOffers extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Expire unselected offers whose validity deadline has passed';

    public function handle(ManageOffers $action): int
    {
        $expired = 0;
        Offer::query()->whereIn('status', [OfferStatus::Active, OfferStatus::PendingApproval])->where('valid_until', '<=', now())
            ->chunkById(100, function (Collection $offers) use ($action, &$expired): void {
                foreach ($offers as $offer) {
                    if ($action->expire($offer)) {
                        $expired++;
                    }
                }
            });
        $this->info("Expired {$expired} offer(s).");

        return self::SUCCESS;
    }
}
