<?php

use App\Actions\ManageFeePayments;
use App\BankRole;
use App\Models\DealInstructionChange;
use App\Models\SettlementInstruction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('independently replaces pending deal instructions while freezing fee and selected terms', function () {
    $borrower = bankStaff(BankRole::Approver);
    $deal = paymentDeal($borrower);
    $maker = bankStaff(BankRole::Maker, $deal->lenderBank);
    $approver = bankStaff(BankRole::Approver, $deal->lenderBank);
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id, 'details' => instructionPayload(['account_number' => 'NEW000099998888'])]);
    $oldSnapshot = $deal->instruction_snapshot;
    $terms = $deal->terms;
    $fee = $deal->fee_amount_minor;
    $deadline = $deal->fee_deadline->toIso8601String();
    signInUser($maker);
    $changeId = $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $instruction->id])->assertCreated()->json('data.id');
    expect($deal->fresh()->instruction_snapshot)->toBe($oldSnapshot);
    signInUser($borrower);
    $response = $this->getJson(route('api.deals.show', $deal))->assertOk()->assertJsonPath('data.instructionReviewPending', true)->assertJsonPath('data.can.initiatePayment', false)->assertJsonMissingPath('data.instructionChange');
    expect($response->getContent())->not->toContain('NEW000099998888')->not->toContain($instruction->id);
    signInUser($approver);
    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $changeId]), ['decision' => 'approve'])->assertOk();
    expect($deal->fresh()->instruction_snapshot['details']['account_number'])->toBe('NEW000099998888');
    expect($deal->fresh()->terms)->toBe($terms);
    expect($deal->fresh()->fee_amount_minor)->toBe($fee);
    expect($deal->fresh()->fee_deadline->toIso8601String())->toBe($deadline);
    expect(DealInstructionChange::query()->sole()->previous_snapshot)->toBe($oldSnapshot);
    expect(DB::table('deal_instruction_changes')->value('previous_snapshot'))->not->toContain('DEMO000012345678');
    $payment = app(ManageFeePayments::class)->initiate($borrower, $deal->fresh());
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk()->assertJsonPath('result', 'verified');
    signInUser($borrower);
    $this->getJson(route('api.deals.settlement', $deal))->assertOk()->assertJsonPath('data.instructions.accountNumber', 'NEW000099998888');
});

it('rejects instruction change self approval with combined roles with 403', function () {
    $deal = paymentDeal(bankStaff());
    $maker = bankStaff(BankRole::Maker, $deal->lenderBank);
    $maker->membership->update(['roles' => ['treasury_maker', 'treasury_approver']]);
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id]);
    signInUser($maker->fresh());
    $changeId = $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $instruction->id])->assertCreated()->json('data.id');

    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $changeId]), ['decision' => 'approve'])->assertForbidden();
    expect($deal->fresh()->settlement_instruction_id)->not->toBe($instruction->id);
});

it('hides instruction changes from unrelated banks and denies borrower treasury with 403', function () {
    $borrower = bankStaff();
    $deal = paymentDeal($borrower);
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id]);
    signInUser($borrower);
    $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $instruction->id])->assertForbidden();
    signInUser(bankStaff());
    $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $instruction->id])->assertNotFound();
    $this->assertDatabaseCount('deal_instruction_changes', 0);
});

it('requires an own current approved instruction version with 422', function (string $case) {
    $deal = paymentDeal(bankStaff());
    $maker = bankStaff(BankRole::Maker, $deal->lenderBank);
    $instruction = match ($case) {
        'foreign version' => SettlementInstruction::factory()->approved()->create(),
        'pending version' => SettlementInstruction::factory()->create(['bank_id' => $deal->lender_bank_id]),
        default => SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id]),
    };
    if ($case === 'stale identity') {
        $deal->lenderBank->update(['name' => 'New current bank identity']);
    }
    signInUser($maker);

    $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $instruction->id])->assertUnprocessable()->assertJsonValidationErrors('settlement_instruction_id');
})->with(['foreign version', 'pending version', 'stale identity']);

it('blocks instruction changes while a payment is pending with 409', function () {
    $borrower = bankStaff(BankRole::Approver);
    $deal = paymentDeal($borrower);
    app(ManageFeePayments::class)->initiate($borrower, $deal);
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id]);
    signInUser(bankStaff(BankRole::Maker, $deal->lenderBank));

    $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $instruction->id])->assertConflict();
    $this->assertDatabaseCount('deal_instruction_changes', 0);
});

it('blocks payment while an instruction change is pending and permits retry after rejection', function () {
    $borrower = bankStaff(BankRole::Approver);
    $deal = paymentDeal($borrower);
    $change = DealInstructionChange::factory()->create(['deal_id' => $deal->id]);
    signInUser($borrower);
    $this->postJson(route('api.payments.store', $deal))->assertConflict();
    signInUser(bankStaff(BankRole::Approver, $deal->lenderBank));
    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $change]), ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $change]), ['decision' => 'reject', 'reason' => 'Existing account instructions remain applicable.'])->assertOk();
    signInUser($borrower);
    $this->postJson(route('api.payments.store', $deal))->assertCreated();
});

it('does not allow an approved instruction update to be replayed with 409', function () {
    $deal = paymentDeal(bankStaff());
    $change = DealInstructionChange::factory()->create(['deal_id' => $deal->id]);
    signInUser(bankStaff(BankRole::Approver, $deal->lenderBank));
    $route = route('api.deal-instructions.review', ['deal' => $deal, 'change' => $change]);
    $this->postJson($route, ['decision' => 'approve'])->assertOk();
    $this->postJson($route, ['decision' => 'approve'])->assertConflict();
});

it('forbids silent instruction changes after verified unlock with 409', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    $deal = $payment->deal;
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id]);
    signInUser(bankStaff(BankRole::Maker, $deal->lenderBank));

    $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $instruction->id])->assertConflict();
    expect($deal->fresh()->hasVerifiedFee())->toBeTrue();
});

it('supersedes pending instruction changes when the fee reservation expires', function () {
    $deal = paymentDeal(bankStaff());
    $change = DealInstructionChange::factory()->create(['deal_id' => $deal->id]);
    $deal->forceFill(['fee_deadline' => now()])->save();
    $this->artisan('deals:expire-fees')->assertSuccessful();

    expect($change->fresh()->status)->toBe('superseded');
    expect($change->fresh()->pending_slot)->toBeNull();
});

it('enforces only one pending instruction change per deal in the database', function () {
    $deal = paymentDeal(bankStaff());
    DealInstructionChange::factory()->create(['deal_id' => $deal->id]);

    expect(fn () => DealInstructionChange::factory()->create(['deal_id' => $deal->id]))->toThrow(QueryException::class);
});

it('shows an identity free borrower instruction update notice', function () {
    $borrower = bankStaff();
    $deal = paymentDeal($borrower);
    $change = DealInstructionChange::factory()->create(['deal_id' => $deal->id]);
    signInUser(bankStaff(BankRole::Approver, $deal->lenderBank));
    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $change]), ['decision' => 'approve'])->assertOk();
    signInUser($borrower);

    $this->get(route('deals.show', $deal))->assertOk()->assertSee('Settlement instructions were independently reviewed and updated.')
        ->assertDontSee($deal->lenderBank->name)->assertDontSee('DEMO000012345678');
    $this->assertDatabaseHas('audit_events', ['action' => 'deal.instructions_updated', 'bank_id' => $deal->borrower_bank_id, 'actor_id' => null]);
});
