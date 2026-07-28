<?php

namespace Database\Factories;

use App\Models\FeePayment;
use App\Models\PaymentReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentReceipt> */
class PaymentReceiptFactory extends Factory
{
    protected $model = PaymentReceipt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fee_payment_id' => FeePayment::factory(), 'provider' => 'demo', 'charge_reference' => (string) Str::uuid(),
            'payment_reference' => fn (array $a): string => FeePayment::query()->find($a['fee_payment_id'])?->provider_reference ?? 'XFP-'.Str::uuid(),
            'reported_deal_id' => fn (array $a): string => FeePayment::query()->find($a['fee_payment_id'])?->deal_id ?? (string) Str::ulid(),
            'amount_minor' => fn (array $a): int => FeePayment::query()->find($a['fee_payment_id'])?->amount_minor ?? 10000,
            'currency' => fn (array $a): string => FeePayment::query()->find($a['fee_payment_id'])?->currency ?? 'LKR',
            'status' => 'refund_review', 'reason' => 'manual_review', 'received_at' => now(),
        ];
    }
}
