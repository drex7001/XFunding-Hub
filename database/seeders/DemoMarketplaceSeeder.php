<?php

namespace Database\Seeders;

use App\Actions\ManageOffers;
use App\Actions\ManageSettlementInstructions;
use App\BankRole;
use App\BankStatus;
use App\FundingRequestStatus;
use App\Models\Bank;
use App\Models\BankMembership;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\SettlementInstruction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Gate;

class DemoMarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Demo marketplace data may only be seeded locally or in tests.');
        }
        $this->call(DemoFundingRequestSeeder::class);
        $this->additionalDemoLender();
        $requests = FundingRequest::query()->whereHas('bank', fn (Builder $query): Builder => $query->where('registration_number', 'DEMO-borrower'))
            ->whereIn('title', ['Working capital liquidity', 'Offer comparison walkthrough'])->where('status', FundingRequestStatus::Open)->where('bidding_deadline', '>', now())->get();
        foreach (['lender-one' => '8.1250', 'lender-three' => '7.9000'] as $key => $rate) {
            $maker = User::query()->where('email', $key.'.maker@xfunding.example')->with('membership.bank')->first();
            $approver = User::query()->where('email', $key.'.approver@xfunding.example')->with('membership.bank')->first();
            if (! $maker || ! $approver || ! Gate::forUser($maker)->allows('create', SettlementInstruction::class) || ! $approver->hasBankRole(BankRole::Approver) || ! $approver->canBrowseMarketplace()) {
                continue;
            }
            $instruction = SettlementInstruction::query()->where('bank_id', $maker->membership->bank_id)->where('status', 'approved')->with('bank')->latest('version')->get()->first(fn (SettlementInstruction $record): bool => $record->matchesBankProfile());
            if (! $instruction) {
                $instructions = app(ManageSettlementInstructions::class);
                $instruction = $instructions->create($maker, [
                    'channel' => 'swift', 'account_name' => $maker->membership->bank->name.' Demo Treasury',
                    'account_number' => $key === 'lender-one' ? 'DEMO000011110000' : 'DEMO000033330000',
                    'bic' => 'DEMOLKXX', 'contact_name' => ucfirst($key).' Demo Treasury', 'contact_email' => $key.'.treasury@xfunding.example',
                ]);
                $instruction = $instructions->review($approver, $instruction, true, 'Synthetic local demonstration instructions.');
            }
            $offers = app(ManageOffers::class);
            foreach ($requests as $request) {
                if (Offer::query()->where('bank_id', $maker->membership->bank_id)->where('funding_request_id', $request->id)->exists()) {
                    continue;
                }
                $offer = $offers->create($maker, $request, [
                    'rate' => $rate, 'collateral' => $key === 'lender-one' ? 'unsecured' : 'sovereign_bonds',
                    'interest_payment' => 'at_maturity', 'valid_until' => $request->settlement_date->endOfDay(),
                    'settlement_instruction_id' => $instruction->id,
                ]);
                $offers->submit($maker, $offer);
                $offers->review($approver, $offer, OfferApproval::query()->where('offer_id', $offer->id)->where('status', 'pending')->firstOrFail(), true, null);
            }
        }
    }

    private function additionalDemoLender(): void
    {
        $bank = Bank::query()->firstOrCreate(['registration_number' => 'DEMO-lender-three'], ['name' => 'Meridian Bank (Demo)', 'country_code' => 'LK', 'requested_capabilities' => ['lender']]);
        if ($bank->wasRecentlyCreated) {
            $bank->forceFill(['status' => BankStatus::Verified, 'can_lend' => true, 'can_borrow' => false, 'verified_at' => now()])->save();
        }
        foreach (['admin' => BankRole::Admin, 'maker' => BankRole::Maker, 'approver' => BankRole::Approver, 'auditor' => BankRole::Auditor] as $suffix => $role) {
            $user = User::query()->firstOrCreate(['email' => 'lender-three.'.$suffix.'@xfunding.example'], ['name' => 'Lender-three '.ucfirst($suffix), 'password' => 'DemoBank123!']);
            if ($user->wasRecentlyCreated) {
                $user->forceFill(['email_verified_at' => now()])->save();
                BankMembership::create(['user_id' => $user->id, 'bank_id' => $bank->id, 'roles' => [$role->value], 'active' => true]);
            }
        }
    }
}
