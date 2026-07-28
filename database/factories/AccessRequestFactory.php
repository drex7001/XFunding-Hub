<?php

namespace Database\Factories;

use App\BankRole;
use App\Models\AccessRequest;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccessRequest> */
class AccessRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['bank_id' => Bank::factory(), 'requested_by' => User::factory(), 'kind' => 'invitation', 'name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'roles' => [BankRole::Maker->value], 'status' => 'pending'];
    }
}
