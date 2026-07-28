<?php

namespace Database\Factories;

use App\BankStatus;
use App\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bank> */
class BankFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->unique()->company().' Bank', 'country_code' => 'LK', 'registration_number' => fake()->unique()->bothify('REG-########'), 'requested_capabilities' => ['borrower', 'lender'], 'status' => BankStatus::Pending, 'can_borrow' => false, 'can_lend' => false];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['status' => BankStatus::Verified, 'can_borrow' => true, 'can_lend' => true, 'verified_at' => now()]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => BankStatus::Suspended, 'can_borrow' => false, 'can_lend' => false]);
    }
}
