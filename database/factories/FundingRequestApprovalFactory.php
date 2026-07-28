<?php

namespace Database\Factories;

use App\Models\FundingRequest;
use App\Models\FundingRequestApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FundingRequestApprovalFactory extends Factory
{
    protected $model = FundingRequestApproval::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'funding_request_id' => FundingRequest::factory()->pending(), 'proposed_by' => User::factory(),
            'expected_version' => 1, 'kind' => 'publish', 'status' => 'pending',
            'bank_profile' => function (array $attributes): array {
                $bank = FundingRequest::query()->with('bank')->findOrFail($attributes['funding_request_id'])->bank;

                return ['name' => $bank->name, 'country_code' => $bank->country_code, 'registration_number' => $bank->registration_number];
            },
        ];
    }
}
