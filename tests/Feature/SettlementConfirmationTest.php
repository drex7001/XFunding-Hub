<?php

use App\Actions\ManageSettlementConfirmations;
use App\BankRole;
use App\BankStatus;
use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\DealEvent;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('requires independent confirmations from both institutions and atomically settles the request', function () {
    $deal = unlockedDeal();
    confirmedInstitution($deal, $deal->borrowerBank);
    expect($deal->fresh()->status)->toBe(DealStatus::Unlocked);
    expect($deal->fundingRequest->fresh()->status)->toBe(FundingRequestStatus::Selected);
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->getJson(route('api.deals.show', $deal))->assertOk()
        ->assertJsonPath('data.settlement.borrower', 'approved')->assertJsonPath('data.settlement.lender', 'not_submitted');

    confirmedInstitution($deal->fresh(), $deal->lenderBank);
    expect($deal->fresh()->status)->toBe(DealStatus::Settled)
        ->and($deal->fresh()->settled_at)->not->toBeNull()
        ->and($deal->fundingRequest->fresh()->status)->toBe(FundingRequestStatus::Settled)
        ->and(DealEvent::query()->where('kind', 'settled')->count())->toBe(1);
    $this->getJson(route('api.deals.settlement', $deal))->assertOk();
    signInUser(bankStaff(BankRole::Maker, $deal->lenderBank));
    $this->getJson(route('api.funding.show', $deal->funding_request_id))->assertOk();
});

it('accepts only a participating treasury maker proposal', function (string $role, string $side, int $status) {
    $deal = unlockedDeal();
    $bank = match ($side) {
        'borrower' => $deal->borrowerBank, 'lender' => $deal->lenderBank, default => null
    };
    $actor = $role === 'platform' ? User::factory()->platformAdmin()->create() : bankStaff(BankRole::from($role), $bank);
    signInUser($actor);
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertStatus($status);
})->with([
    ['treasury_maker', 'borrower', 201], ['treasury_maker', 'lender', 201],
    ['treasury_approver', 'borrower', 403], ['bank_admin', 'borrower', 403], [BankRole::Auditor->value, 'borrower', 403],
    ['treasury_maker', 'unrelated', 404], ['platform', 'borrower', 404],
]);

it('enforces same-bank review and prevents self approval even with both roles', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $maker->membership->update(['roles' => [BankRole::Maker->value, BankRole::Approver->value]]);
    $confirmation = app(ManageSettlementConfirmations::class)->propose($maker->fresh(['membership.bank']), $deal, settlementPayload());
    foreach ([$maker, bankStaff(BankRole::Approver, $deal->lenderBank), bankStaff(BankRole::Admin, $deal->borrowerBank)] as $actor) {
        signInUser($actor);
        $this->postJson(route('api.confirmations.review', [$deal, $confirmation]), ['decision' => 'approve'])->assertForbidden();
    }
    expect($confirmation->fresh()->status)->toBe('pending');
});

it('rejects duplicate proposals and approval replay', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    signInUser($maker);
    $id = $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertCreated()->json('data.id');
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertConflict();
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->postJson(route('api.confirmations.review', [$deal, $id]), ['decision' => 'approve'])->assertOk();
    $this->postJson(route('api.confirmations.review', [$deal, $id]), ['decision' => 'approve'])->assertConflict();
    expect(DealEvent::query()->where('kind', 'confirmation.approved')->count())->toBe(1);
});

it('rejects with a reason and permits a fresh immutable proposal', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $confirmation = app(ManageSettlementConfirmations::class)->propose($maker, $deal, settlementPayload());
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->postJson(route('api.confirmations.review', [$deal, $confirmation]), ['decision' => 'reject'])->assertUnprocessable();
    $this->postJson(route('api.confirmations.review', [$deal, $confirmation]), ['decision' => 'reject', 'reason' => 'Reference requires correction.'])->assertOk();
    signInUser($maker);
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertCreated();
    expect($confirmation->fresh()->status)->toBe('rejected')->and($confirmation->fresh()->reference)->toBe('SYNTHETIC-OFFPLATFORM-REF');
});

it('prohibits forged ownership and approval fields', function (string $field) {
    $deal = unlockedDeal();
    signInUser(bankStaff(BankRole::Maker, $deal->borrowerBank));
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload() + [$field => 'forged'])->assertUnprocessable();
    expect(SettlementConfirmation::query()->count())->toBe(0);
})->with(['bank_id', 'proposed_by', 'reviewed_by', 'status', 'approved_slot', 'pending_slot', 'settlement_instruction_id']);

it('requires fee proof and current eligible institutions and instructions for proposal', function (string $failure) {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    match ($failure) {
        'fee' => $deal->forceFill(['verified_payment_id' => null])->save(),
        'lender suspended' => $deal->lenderBank->forceFill(['status' => BankStatus::Suspended])->save(),
        'capability' => $deal->lenderBank->forceFill(['can_lend' => false])->save(),
        'instructions' => $deal->settlementInstruction->forceFill(['status' => 'rejected'])->save(),
        'settled' => $deal->forceFill(['status' => DealStatus::Settled])->save(),
        'cancelled' => $deal->forceFill(['status' => DealStatus::Cancelled])->save(),
    };
    signInUser($maker);
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertConflict();
})->with(['fee', 'lender suspended', 'capability', 'instructions', 'settled', 'cancelled']);

it('rechecks eligibility and the proposing membership under review', function (string $change) {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $record = app(ManageSettlementConfirmations::class)->propose($maker, $deal, settlementPayload());
    match ($change) {
        'revoked' => $maker->membership->update(['active' => false]),
        'role' => $maker->membership->update(['roles' => [BankRole::Auditor->value]]),
        'suspension' => $deal->lenderBank->forceFill(['status' => BankStatus::Suspended])->save(),
        'version' => $deal->settlementInstruction->forceFill(['status' => 'rejected'])->save(),
    };
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->postJson(route('api.confirmations.review', [$deal, $record]), ['decision' => 'approve'])->assertConflict();
    expect($record->fresh()->status)->toBe('pending');
})->with(['revoked', 'role', 'suspension', 'version']);

it('rejects future or pre-unlock settlement times', function (string $time) {
    $deal = unlockedDeal();
    signInUser(bankStaff(BankRole::Maker, $deal->borrowerBank));
    $payload = settlementPayload();
    $payload['settlement_at'] = ($time === 'future' ? now()->addMinute() : now()->subMinute())->toIso8601String();
    $this->postJson(route('api.confirmations.store', $deal), $payload)->assertUnprocessable();
})->with(['future', 'before unlock']);

it('keeps attestation references encrypted and private to own treasury users', function () {
    $deal = unlockedDeal();
    $record = app(ManageSettlementConfirmations::class)->propose(bankStaff(BankRole::Maker, $deal->borrowerBank), $deal, settlementPayload());
    expect(DB::table('settlement_confirmations')->value('reference'))->not->toContain('SYNTHETIC-OFFPLATFORM-REF');
    foreach ([bankStaff(BankRole::Auditor, $deal->borrowerBank), bankStaff(BankRole::Admin, $deal->borrowerBank), bankStaff(BankRole::Maker, $deal->lenderBank)] as $actor) {
        signInUser($actor);
        $this->get(route('deals.show', $deal))->assertOk()->assertDontSee('SYNTHETIC-OFFPLATFORM-REF')->assertDontSee('Private institution evidence');
    }
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->get(route('deals.show', $deal))->assertOk()->assertSee('SYNTHETIC-OFFPLATFORM-REF')->assertHeader('Cache-Control', 'no-store, private');
});

it('enforces pending-slot uniqueness in the database', function () {
    $deal = unlockedDeal();
    SettlementConfirmation::factory()->create(['deal_id' => $deal->id]);
    expect(fn () => SettlementConfirmation::factory()->create(['deal_id' => $deal->id]))->toThrow(QueryException::class);
});

it('rolls back the second confirmation and settled state when audit persistence fails', function () {
    $deal = unlockedDeal();
    confirmedInstitution($deal, $deal->borrowerBank);
    $record = app(ManageSettlementConfirmations::class)->propose(bankStaff(BankRole::Maker, $deal->lenderBank), $deal, settlementPayload());
    DealEvent::creating(function (DealEvent $event): void {
        if ($event->kind === 'settled') {
            throw new RuntimeException('Synthetic persistence failure');
        }
    });
    try {
        expect(fn () => app(ManageSettlementConfirmations::class)->review(bankStaff(BankRole::Approver, $deal->lenderBank), $record, true, null))->toThrow(RuntimeException::class);
    } finally {
        DealEvent::flushEventListeners();
    }
    expect($record->fresh()->status)->toBe('pending')
        ->and($deal->fresh()->status)->toBe(DealStatus::Unlocked)
        ->and($deal->fundingRequest->fresh()->status)->toBe(FundingRequestStatus::Selected);
});

it('does not accept confirmation IDs attached to another deal', function () {
    $deal = unlockedDeal();
    $other = unlockedDeal();
    $record = SettlementConfirmation::factory()->create(['deal_id' => $other->id]);
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->postJson(route('api.confirmations.review', [$deal, $record]), ['decision' => 'approve'])->assertNotFound();
});
