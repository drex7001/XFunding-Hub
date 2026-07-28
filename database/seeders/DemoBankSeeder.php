<?php

namespace Database\Seeders;

use App\BankRole;
use App\BankStatus;
use App\Models\Bank;
use App\Models\BankMembership;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoBankSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Demo bank accounts may only be seeded locally or in tests.');
        }
        User::query()->firstOrNew(['email' => 'operations@xfunding.example'])->forceFill(['name' => 'Platform Operations', 'password' => 'DemoBank123!', 'is_platform_admin' => true, 'active' => true, 'email_verified_at' => now()])->save();
        foreach (['borrower' => 'Harbor Bank (Demo)', 'lender-one' => 'Northbridge Bank (Demo)', 'lender-two' => 'Summit Bank (Demo)'] as $key => $name) {
            $bank = Bank::query()->firstOrNew(['registration_number' => 'DEMO-'.$key]);
            $bank->forceFill(['name' => $name, 'country_code' => 'LK', 'status' => $key === 'lender-two' ? BankStatus::Pending : BankStatus::Verified, 'requested_capabilities' => [$key === 'borrower' ? 'borrower' : 'lender'], 'can_borrow' => $key === 'borrower', 'can_lend' => $key === 'lender-one', 'verified_at' => $key === 'lender-two' ? null : now()])->save();
            foreach (BankRole::cases() as $role) {
                $suffix = match ($role) {
                    BankRole::Admin => 'admin', BankRole::Maker => 'maker', BankRole::Approver => 'approver', BankRole::Auditor => 'auditor'
                };
                $user = User::query()->firstOrNew(['email' => $key.'.'.$suffix.'@xfunding.example']);
                $user->forceFill(['name' => ucfirst($key).' '.ucfirst($suffix), 'password' => 'DemoBank123!', 'active' => true, 'is_platform_admin' => false, 'email_verified_at' => now()])->save();
                BankMembership::query()->updateOrCreate(['user_id' => $user->id], ['bank_id' => $bank->id, 'roles' => [$role->value], 'active' => true]);
            }
        }
    }
}
