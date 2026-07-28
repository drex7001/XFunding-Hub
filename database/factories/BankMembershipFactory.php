<?php

namespace Database\Factories;

use App\BankRole;
use App\Models\Bank;
use App\Models\BankMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankMembership> */
class BankMembershipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['bank_id' => Bank::factory(), 'user_id' => User::factory(), 'roles' => [BankRole::Maker->value], 'active' => true, 'version' => 0];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['roles' => [BankRole::Admin->value]]);
    }

    public function approver(): static
    {
        return $this->state(fn () => ['roles' => [BankRole::Approver->value]]);
    }

    public function auditor(): static
    {
        return $this->state(fn () => ['roles' => [BankRole::Auditor->value]]);
    }
}
