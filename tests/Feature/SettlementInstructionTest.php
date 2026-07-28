<?php

use App\BankRole;
use App\Models\SettlementInstruction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->freezeTime();
});

it('requires authentication for settlement instructions with 401', function () {
    $this->getJson(route('api.instructions.index'))->assertUnauthorized();
});

it('proposes encrypted instructions and independently approves an immutable version', function () {
    $maker = bankStaff();
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    signInUser($maker);

    $this->postJson(route('api.instructions.store'), instructionPayload())->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'pending');
    $instruction = SettlementInstruction::query()->sole();
    $encrypted = DB::table('settlement_instructions')->value('details');
    expect($encrypted)->not->toContain('DEMO000098765432')->not->toContain('desk@synthetic.example');
    signInUser($approver);
    $this->postJson(route('api.instructions.review', $instruction), ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
    $this->assertDatabaseHas('settlement_instructions', ['id' => $instruction->id, 'reviewed_by' => $approver->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'instructions.approved', 'bank_id' => $maker->membership->bank_id]);
});

it('enforces the lender instruction role matrix at the policy boundary', function (BankRole $role) {
    $user = bankStaff($role);
    $instruction = SettlementInstruction::factory()->create(['bank_id' => $user->membership->bank_id]);
    $gate = Gate::forUser($user);

    expect($gate->allows('viewAny', SettlementInstruction::class))->toBe(in_array($role, [BankRole::Maker, BankRole::Approver], true));
    expect($gate->allows('create', SettlementInstruction::class))->toBe($role === BankRole::Maker);
    expect($gate->allows('review', $instruction))->toBe($role === BankRole::Approver);
})->with(BankRole::cases());

it('does not expose instruction details across banks or to platform accounts with 404', function () {
    $instruction = SettlementInstruction::factory()->approved()->create();
    signInUser(bankStaff());
    $this->getJson(route('api.instructions.show', $instruction))->assertNotFound();
    $this->postJson(route('api.instructions.review', $instruction), ['decision' => 'approve'])->assertNotFound();
    signInUser(User::factory()->platformAdmin()->create());
    $this->getJson(route('api.instructions.show', $instruction))->assertNotFound();
});

it('does not allow an auditor to read its own banking instructions with 404', function () {
    $auditor = bankStaff(BankRole::Auditor);
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $auditor->membership->bank_id]);
    signInUser($auditor);

    $this->getJson(route('api.instructions.show', $instruction))->assertNotFound();
    $this->postJson(route('api.instructions.store'), instructionPayload())->assertForbidden();
});

it('rejects instruction self approval even when the maker has both roles with 403', function () {
    $maker = bankStaff();
    $maker->membership->update(['roles' => ['treasury_maker', 'treasury_approver']]);
    $instruction = SettlementInstruction::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    signInUser($maker->fresh());

    $this->postJson(route('api.instructions.review', $instruction), ['decision' => 'approve'])->assertForbidden();
    expect($instruction->fresh()->status)->toBe('pending');
});

it('requires a rejection reason and prevents reviewed instruction replay with 409', function () {
    $maker = bankStaff();
    $instruction = SettlementInstruction::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));
    $this->postJson(route('api.instructions.review', $instruction), ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson(route('api.instructions.review', $instruction), ['decision' => 'reject', 'reason' => 'Account requires verification.'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson(route('api.instructions.review', $instruction), ['decision' => 'approve'])->assertConflict();
});

it('requires a fresh instruction proposal after the bank identity changes with 409', function () {
    $maker = bankStaff();
    $instruction = SettlementInstruction::factory()->create(['bank_id' => $maker->membership->bank_id]);
    $maker->membership->bank->update(['name' => 'Changed legal bank identity']);
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));

    $this->postJson(route('api.instructions.review', $instruction), ['decision' => 'approve'])->assertConflict();
    expect($instruction->fresh()->status)->toBe('pending');
});

it('creates a new instruction version without modifying the old approved account', function () {
    $maker = bankStaff();
    $old = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->postJson(route('api.instructions.store'), instructionPayload(['account_number' => 'NEW000012345678']))->assertCreated()->assertJsonPath('data.version', 2);
    expect($old->fresh()->details['account_number'])->toBe('DEMO000012345678');
    expect($old->fresh()->status)->toBe('approved');
});

it('rejects invalid instructions and forged ownership with 422', function (array $overrides, string $field) {
    signInUser(bankStaff());

    $this->postJson(route('api.instructions.store'), instructionPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('settlement_instructions', 0);
})->with([
    'unsupported channel' => [['channel' => 'email'], 'channel'],
    'bad account' => [['account_number' => 'bad spaces'], 'account_number'],
    'bad BIC' => [['bic' => '123'], 'bic'],
    'missing RTGS routing' => [['channel' => 'rtgs'], 'rtgs_code'],
    'bad contact' => [['contact_email' => 'invalid'], 'contact_email'],
    'forged bank' => [['bank_id' => 999], 'bank_id'],
    'forged approval' => [['status' => 'approved'], 'status'],
]);

it('escapes lender contact and review note text in the private instruction screen', function () {
    $maker = bankStaff();
    $dangerous = '<script>alert("contact")</script>';
    $instruction = SettlementInstruction::factory()->create(['bank_id' => $maker->membership->bank_id, 'details' => instructionPayload(['contact_name' => $dangerous]), 'reason' => $dangerous]);
    signInUser($maker);

    $this->get(route('instructions.show', $instruction))->assertOk()->assertSee(e($dangerous), false)->assertDontSee($dangerous, false);
});

it('accepts an alphanumeric BIC party prefix with an alphabetic country and optional branch', function () {
    signInUser(bankStaff());

    $this->postJson(route('api.instructions.store'), instructionPayload(['bic' => 'A1B2LK33XXX']))->assertCreated()->assertJsonPath('data.details.bic', 'A1B2LK33XXX');
});
