<?php

namespace Database\Factories;

use App\Models\FeePayment;
use App\Models\PaymentNotification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentNotification> */
class PaymentNotificationFactory extends Factory
{
    protected $model = PaymentNotification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fee_payment_id' => FeePayment::factory(), 'provider' => 'demo', 'event_reference' => (string) Str::uuid(),
            'payload_sha256' => hash('sha256', 'synthetic notification'),
            'payment_reference' => fn (array $a): string => FeePayment::query()->findOrFail($a['fee_payment_id'])->provider_reference,
            'reported_deal_id' => fn (array $a): string => FeePayment::query()->findOrFail($a['fee_payment_id'])->deal_id,
            'outcome' => 'ignored',
        ];
    }
}
