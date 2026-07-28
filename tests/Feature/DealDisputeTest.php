<?php

use App\Actions\ChangeDealInstructions;
use App\Actions\ManageDealDisputes;
use App\Actions\ManageSettlementConfirmations;
use App\BankRole;
use App\BankStatus;
use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\AuditEvent;
use App\Models\DealDispute;
use App\Models\DealEvent;
use App\Models\SettlementInstruction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('lets only a participating approver open an unlocked dispute', function (BankRole $role) {
    $deal = unlockedDeal();
    signInUser(bankStaff($role, $deal->borrowerBank));
    $this->postJson(route('api.disputes.store', $deal), ['description' => 'The receiving treasury reports a settlement discrepancy.'])
        ->assertStatus($role === BankRole::Approver ? 201 : 403);
})->with(BankRole::cases());

it('hides disputes from unrelated banks and platform treasury callers', function () {
    $deal = unlockedDeal();
    foreach ([bankStaff(BankRole::Approver), User::factory()->platformAdmin()->create()] as $user) {
        signInUser($user);
        $this->postJson(route('api.disputes.store', $deal), ['description' => 'Synthetic issue for review.'])->assertNotFound();
    }
});

it('requires real fee proof and refuses duplicate or terminal dispute creation', function (string $state) {
    $deal = unlockedDeal();
    $approver = bankStaff(BankRole::Approver, $deal->borrowerBank);
    if ($state === 'duplicate') {
        app(ManageDealDisputes::class)->open($approver, $deal, 'Synthetic settlement discrepancy.');
    } elseif ($state === 'no proof') {
        $deal->forceFill(['verified_payment_id' => null])->save();
    } else {
        $deal->forceFill(['status' => DealStatus::from($state)])->save();
    }
    signInUser($approver);
    $this->postJson(route('api.disputes.store', $deal), ['description' => 'Synthetic settlement discrepancy.'])->assertConflict();
})->with(['duplicate', 'no proof', 'awaiting_fee', 'settled', 'closed_unsettled', 'cancelled']);

it('allows the eligible institution to report an issue when the other bank is suspended', function () {
    $deal = unlockedDeal();
    $deal->lenderBank->forceFill(['status' => BankStatus::Suspended])->save();
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->postJson(route('api.disputes.store', $deal), ['description' => 'Counterparty became ineligible before settlement.'])->assertCreated();
    expect($deal->fresh()->status)->toBe(DealStatus::Disputed);
});

it('collects both confirmations during dispute but waits for operations resolution', function () {
    $deal = unlockedDeal();
    $dispute = app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->lenderBank), $deal, 'Synthetic discrepancy pending investigation.');
    confirmedInstitution($deal->fresh(), $deal->borrowerBank);
    confirmedInstitution($deal->fresh(), $deal->lenderBank);
    expect($deal->fresh()->status)->toBe(DealStatus::Disputed)
        ->and($deal->fundingRequest->fresh()->status)->toBe(FundingRequestStatus::Selected);
    signInUser(User::factory()->platformAdmin()->create());
    $this->postJson(route('disputes.resolve', $dispute), ['outcome' => 'settled', 'reason' => 'Both institutions supplied independently approved attestations.'])
        ->assertOk()->assertJsonPath('data.status', 'settled')->assertJsonMissingPath('data.instructions');
    expect($dispute->fresh()->resolved_at)->not->toBeNull()
        ->and($dispute->fresh()->open_slot)->toBeNull()
        ->and($deal->fundingRequest->fresh()->status)->toBe(FundingRequestStatus::Settled);
});

it('never lets operations fabricate settlement without both confirmations', function (int $confirmed) {
    $deal = unlockedDeal();
    $dispute = app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal, 'Synthetic discrepancy pending investigation.');
    if ($confirmed === 1) {
        confirmedInstitution($deal->fresh(), $deal->borrowerBank);
    }
    signInUser(User::factory()->platformAdmin()->create());
    $this->postJson(route('disputes.resolve', $dispute), ['outcome' => 'settled', 'reason' => 'Operations has reviewed available evidence.'])->assertConflict();
    expect($deal->fresh()->status)->toBe(DealStatus::Disputed);
})->with([0, 1]);

it('closes an unsettled dispute with a reason and supersedes pending attestations', function () {
    $deal = unlockedDeal();
    $pending = app(ManageSettlementConfirmations::class)->propose(bankStaff(BankRole::Maker, $deal->borrowerBank), $deal, settlementPayload());
    $dispute = app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal, 'Transfer cannot proceed due to a documented discrepancy.');
    signInUser(User::factory()->platformAdmin()->create());
    $this->postJson(route('disputes.resolve', $dispute), ['outcome' => 'closed_unsettled'])->assertUnprocessable();
    $this->postJson(route('disputes.resolve', $dispute), ['outcome' => 'closed_unsettled', 'reason' => 'Neither institution could provide the required approved confirmations.'])->assertOk();
    expect($deal->fresh()->status)->toBe(DealStatus::ClosedUnsettled)
        ->and($deal->fundingRequest->fresh()->status)->toBe(FundingRequestStatus::Cancelled)
        ->and($pending->fresh()->status)->toBe('superseded')
        ->and($pending->fresh()->pending_slot)->toBeNull();
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->getJson(route('api.deals.settlement', $deal))->assertOk();
});

it('rejects resolution replay and bank-user resolution', function () {
    $deal = unlockedDeal();
    $user = bankStaff(BankRole::Approver, $deal->borrowerBank);
    $dispute = app(ManageDealDisputes::class)->open($user, $deal, 'Synthetic discrepancy pending investigation.');
    $payload = ['outcome' => 'closed_unsettled', 'reason' => 'Unable to obtain approved confirmations.'];
    signInUser($user);
    $this->postJson(route('disputes.resolve', $dispute), $payload)->assertNotFound();
    signInUser(User::factory()->platformAdmin()->create());
    $this->postJson(route('disputes.resolve', $dispute), $payload)->assertOk();
    $this->postJson(route('disputes.resolve', $dispute), $payload)->assertConflict();
    expect(DealEvent::query()->where('kind', 'dispute.resolved')->count())->toBe(1);
});

it('requires a reason for privileged dispute reads and encrypts narratives', function () {
    $deal = unlockedDeal();
    $dispute = app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal, 'SECRET-NARRATIVE synthetic transfer discrepancy.');
    expect(DB::table('deal_disputes')->value('description'))->not->toContain('SECRET-NARRATIVE');
    signInUser(User::factory()->platformAdmin()->create());
    $this->get(route('disputes.operations'))->assertOk()->assertDontSee('SECRET-NARRATIVE');
    $this->postJson(route('disputes.review', $dispute))->assertUnprocessable();
    $this->postJson(route('disputes.review', $dispute), ['reason' => 'Review settlement evidence.'])->assertOk()
        ->assertJsonPath('data.description', 'SECRET-NARRATIVE synthetic transfer discrepancy.')
        ->assertJsonMissingPath('data.lender')->assertJsonMissingPath('data.instructions');
    expect(AuditEvent::query()->where('action', 'deal.dispute_reviewed')->count())->toBe(1);
    signInUser(bankStaff(BankRole::Maker, $deal->lenderBank));
    $this->get(route('deals.show', $deal))->assertOk()->assertDontSee('SECRET-NARRATIVE');
    signInUser(bankStaff(BankRole::Auditor, $deal->borrowerBank));
    $this->get(route('deals.show', $deal))->assertOk()->assertDontSee('SECRET-NARRATIVE');
});

it('independently corrects disputed instructions and requires fresh confirmations', function () {
    $deal = unlockedDeal();
    $terms = $deal->terms;
    $fee = $deal->fee_amount_minor;
    $original = $deal->instruction_snapshot;
    $old = confirmedInstitution($deal, $deal->borrowerBank);
    $dispute = app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal->fresh(), 'Approved account details require a documented correction.');
    $new = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id, 'details' => instructionPayload(['account_number' => 'CORRECTED-DEMO-ACCOUNT'])]);
    $change = app(ChangeDealInstructions::class)->propose(bankStaff(BankRole::Maker, $deal->lenderBank), $deal->fresh(), $new->id);
    signInUser(bankStaff(BankRole::Maker, $deal->borrowerBank));
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertConflict();
    $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
    signInUser(User::factory()->platformAdmin()->create());
    $this->postJson(route('disputes.resolve', $dispute), ['outcome' => 'closed_unsettled', 'reason' => 'Pending instruction review must complete first.'])->assertConflict();
    app(ChangeDealInstructions::class)->review(bankStaff(BankRole::Approver, $deal->lenderBank), $change, true, null);
    expect($old->fresh()->status)->toBe('superseded')->and($old->fresh()->approved_slot)->toBeNull()
        ->and($change->fresh()->previous_snapshot)->toBe($original)
        ->and($deal->fresh()->terms)->toBe($terms)->and($deal->fresh()->fee_amount_minor)->toBe($fee);
    confirmedInstitution($deal->fresh(), $deal->borrowerBank);
    confirmedInstitution($deal->fresh(), $deal->lenderBank);
    app(ManageDealDisputes::class)->resolve(User::factory()->platformAdmin()->create(), $dispute, 'settled', 'Corrected instructions and fresh attestations independently reviewed.');
    expect($deal->fresh()->status)->toBe(DealStatus::Settled);
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->getJson(route('api.deals.settlement', $deal))->assertOk()->assertJsonPath('data.instructions.accountNumber', 'CORRECTED-DEMO-ACCOUNT');
});

it('rechecks bank eligibility before resolving a fully confirmed dispute as settled', function () {
    $deal = unlockedDeal();
    $dispute = app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal, 'Synthetic discrepancy pending investigation.');
    confirmedInstitution($deal->fresh(), $deal->borrowerBank);
    confirmedInstitution($deal->fresh(), $deal->lenderBank);
    $deal->lenderBank->forceFill(['status' => BankStatus::Suspended])->save();
    signInUser(User::factory()->platformAdmin()->create());
    $this->postJson(route('disputes.resolve', $dispute), ['outcome' => 'settled', 'reason' => 'Review current eligibility at resolution.'])->assertConflict();
});

it('enforces a single open dispute through a database constraint', function () {
    $deal = unlockedDeal();
    DealDispute::factory()->create(['deal_id' => $deal->id]);
    expect(fn () => DealDispute::factory()->create(['deal_id' => $deal->id]))->toThrow(QueryException::class);
});
