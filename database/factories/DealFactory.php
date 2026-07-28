<?php

namespace Database\Factories;

use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\SettlementInstruction;
use App\OfferStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deal> */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory()->active(),
            'funding_request_id' => fn (array $a): string => Offer::query()->findOrFail($a['offer_id'])->funding_request_id,
            'borrower_bank_id' => fn (array $a): int => FundingRequest::query()->findOrFail($a['funding_request_id'])->bank_id,
            'lender_bank_id' => fn (array $a): int => Offer::query()->findOrFail($a['offer_id'])->bank_id,
            'settlement_instruction_id' => fn (array $a): string => Offer::query()->findOrFail($a['offer_id'])->settlement_instruction_id,
            'terms' => ['label' => 'Offer 1', 'rate' => '8.1250', 'amount' => '250000000.00', 'currency' => 'LKR', 'tenorDays' => 7, 'dayCount' => 'ACT/365F', 'rateBasis' => 'annual_simple', 'collateral' => 'unsecured', 'interestPayment' => 'at_maturity', 'settlementDate' => now()->addDays(4)->toDateString(), 'borrower' => ['name' => 'Synthetic Borrower']],
            'instruction_snapshot' => function (array $a): array {
                $instruction = SettlementInstruction::query()->findOrFail($a['settlement_instruction_id']);

                return ['bank' => $instruction->bank_profile, 'details' => $instruction->details, 'version' => $instruction->version];
            },
            'offer_valid_until' => now()->addDays(3), 'status' => 'awaiting_fee',
        ];
    }

    public function quoted(): static
    {
        return $this->state(fn (): array => [
            'fee_amount_minor' => 25000000, 'fee_currency' => 'LKR',
            'fee_policy' => ['version' => 'demo-v1', 'mode' => 'demo', 'basis' => 'principal', 'basisPoints' => 10, 'taxBasisPoints' => 0, 'baseMinor' => 25000000, 'taxMinor' => 0, 'windowHours' => 24],
            'fee_quoted_at' => now(), 'fee_deadline' => now()->addHours(24),
        ]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Deal $deal): void {
            Offer::query()->whereKey($deal->offer_id)->update(['status' => OfferStatus::Selected]);
            FundingRequest::query()->whereKey($deal->funding_request_id)->update(['status' => FundingRequestStatus::Selected]);
        });
    }
}
