<?php

namespace Database\Seeders;

use App\Actions\ManageSettlementConfirmations;
use App\BankRole;
use App\DealStatus;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSettlementSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Synthetic settlement examples are restricted to local/test environments.');
        }
        $maker = User::query()->where('email', 'borrower.maker@xfunding.example')->with('membership.bank')->first();
        if ($maker === null || ! $maker->hasVerifiedBankAccess() || ! $maker->hasBankRole(BankRole::Maker)) {
            return;
        }
        $deal = Deal::query()->where('borrower_bank_id', $maker->membership->bank_id)->where('status', DealStatus::Unlocked)
            ->with(['borrowerBank', 'lenderBank', 'settlementInstruction.bank', 'verifiedPayment.verifiedReceipt', 'pendingInstructionChange', 'fundingRequest'])
            ->oldest()->get()->first(fn (Deal $record): bool => $record->settlementReady());
        if ($deal === null || $deal->confirmations()->exists()) {
            return;
        }
        app(ManageSettlementConfirmations::class)->propose($maker, $deal, [
            'settlement_at' => now()->toIso8601String(), 'reference' => 'DEMO-OFFPLATFORM-'.$deal->id,
            'note' => 'Synthetic attestation only. No real interbank transfer occurred.',
        ]);
    }
}
