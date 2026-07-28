<?php

namespace Database\Seeders;

use App\Actions\DemoPaymentProvider;
use App\Actions\ManageFeePayments;
use App\Actions\QuotePlatformFee;
use App\DealStatus;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

class DemoPaymentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app(DemoPaymentProvider::class)->enabled()) {
            throw new \LogicException('Configure local demo payments before preparing their seed quotes.');
        }
        $deals = Deal::query()->where('status', DealStatus::AwaitingFee)->whereNull('fee_amount_minor')
            ->whereHas('fundingRequest.bank', fn (Builder $query): Builder => $query->where('registration_number', 'DEMO-borrower'))->get();
        foreach ($deals as $deal) {
            app(QuotePlatformFee::class)->handle($deal);
            app(ManageFeePayments::class)->expire($deal->fresh());
        }
    }
}
