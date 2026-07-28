<?php

namespace Database\Factories;

use App\FundingRequestStatus;
use App\Models\Bank;
use App\Models\FundingRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FundingRequestFactory extends Factory
{
    protected $model = FundingRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bank_id' => Bank::factory()->verified(), 'created_by' => User::factory(),
            'title' => 'Treasury liquidity request', 'amount_minor' => 25000000000, 'currency' => 'LKR',
            'tenor_days' => 7, 'settlement_date' => now()->addDays(4)->toDateString(),
            'bidding_deadline' => now()->addDays(3), 'day_count' => 'ACT/365F', 'rate_basis' => 'annual_simple',
            'borrower_background' => 'Synthetic borrower background and financial history for treasury review.',
            'status' => FundingRequestStatus::Draft, 'version' => 1,
            'contributor_ids' => fn (array $attributes): array => [(int) $attributes['created_by']],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => FundingRequestStatus::PendingApproval]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => FundingRequestStatus::Open, 'published_at' => now(),
            'borrower_profile' => function (array $attributes): array {
                $bank = Bank::query()->findOrFail($attributes['bank_id']);

                return ['name' => $bank->name, 'country_code' => $bank->country_code, 'registration_number' => $bank->registration_number, 'background' => $attributes['borrower_background']];
            },
        ]);
    }
}
