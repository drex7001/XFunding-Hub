<?php

use App\Actions\ManageBankAccess;
use App\BankRole;
use App\BankStatus;
use App\Models\AccessRequest;
use App\Models\Bank;
use App\Models\BankInvitation;
use App\Models\User;
use App\Notifications\BankInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

it('enforces the bank policy for each staff role', function (BankRole $role) {
    $bank = Bank::factory()->create();
    $otherBank = Bank::factory()->create();
    $user = bankStaff($role, $bank);
    $gate = Gate::forUser($user);

    expect($gate->allows('view', $bank))->toBeTrue();
    expect($gate->allows('view', $otherBank))->toBeFalse();
    expect($gate->allows('update', $bank))->toBe($role === BankRole::Admin);
    expect($gate->allows('manageMembers', $bank))->toBe($role === BankRole::Admin);
    expect($gate->allows('verify', $bank))->toBeFalse();
    expect($gate->allows('create', Bank::class))->toBeFalse();
})->with(BankRole::cases());

it('renders the owning bank workspace without other bank data', function () {
    $user = bankStaff();
    $otherBank = Bank::factory()->create(['name' => 'Private Other Bank']);
    signInUser($user);

    $this->get(route('banks.show', $user->membership->bank))->assertOk()->assertSee($user->membership->bank->name)->assertDontSee($otherBank->name);
});

it('returns 404 for another bank in the page and API', function () {
    $user = bankStaff();
    $bank = Bank::factory()->create();
    signInUser($user);

    $this->get(route('banks.show', $bank))->assertNotFound();
    $this->getJson(route('api.banks.show', $bank))->assertNotFound();
});

it('does not expose authentication secrets in the account resource', function () {
    $user = bankStaff(BankRole::Auditor);
    signInUser($user);

    $this->getJson(route('api.me'))->assertOk()->assertJsonPath('data.roles', ['auditor'])
        ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')->assertJsonMissingPath('data.auth_version');
});

it('limits operations access to platform admins with 403', function () {
    signInUser(bankStaff(BankRole::Admin));

    $this->get(route('operations'))->assertForbidden();
});

it('lets platform operations create a pending institution and invite its first admin', function () {
    signInUser(User::factory()->platformAdmin()->create());
    Notification::fake();

    $this->post(route('banks.store'), ['name' => 'New Institution', 'country_code' => 'LK', 'registration_number' => 'NEW-1', 'requested_capabilities' => ['borrower'], 'admin_name' => 'First Admin', 'admin_email' => 'first@example.test'])->assertRedirect();

    $this->assertDatabaseHas('banks', ['name' => 'New Institution', 'status' => 'pending', 'can_borrow' => false, 'can_lend' => false]);
    $this->assertDatabaseHas('bank_invitations', ['email' => 'first@example.test']);
    Notification::assertSentOnDemand(BankInvitationNotification::class);
});

it('lets operations approve only requested capabilities', function () {
    $bank = Bank::factory()->create(['requested_capabilities' => ['borrower']]);
    signInUser(User::factory()->platformAdmin()->create());

    $this->post(route('banks.verify', $bank), ['capabilities' => ['borrower'], 'reason' => 'Institution documents reviewed.'])->assertRedirect();

    expect($bank->fresh()->status)->toBe(BankStatus::Verified);
    expect($bank->fresh()->can_borrow)->toBeTrue();
    expect($bank->fresh()->can_lend)->toBeFalse();
    $this->assertDatabaseHas('audit_events', ['bank_id' => $bank->id, 'action' => 'bank.verified']);
});

it('rejects unrequested capability approval with 422', function () {
    $bank = Bank::factory()->create(['requested_capabilities' => ['borrower']]);
    signInUser(User::factory()->platformAdmin()->create());

    $this->postJson(route('banks.verify', $bank), ['capabilities' => ['lender'], 'reason' => 'Institution documents reviewed.'])->assertUnprocessable()->assertJsonValidationErrors('capabilities.0');

    expect($bank->fresh()->status)->toBe(BankStatus::Pending);
});

it('requires a fresh verification after a bank admin edits its profile', function () {
    $user = bankStaff(BankRole::Admin);
    $bank = $user->membership->bank;
    signInUser($user);

    $this->patch(route('banks.update', $bank), ['name' => 'Updated Name', 'country_code' => 'LK', 'registration_number' => $bank->registration_number, 'requested_capabilities' => ['borrower']])->assertRedirect();

    $this->assertDatabaseHas('banks', ['id' => $bank->id, 'name' => 'Updated Name', 'status' => 'pending', 'can_borrow' => false, 'can_lend' => false]);
});

it('rejects client supplied verification and privilege attributes with 422', function () {
    $user = bankStaff(BankRole::Admin);
    $bank = $user->membership->bank;
    signInUser($user);

    $this->patchJson(route('banks.update', $bank), ['name' => 'Attack', 'country_code' => 'LK', 'registration_number' => $bank->registration_number, 'requested_capabilities' => ['borrower'], 'status' => 'verified', 'is_platform_admin' => true, 'can_lend' => true])->assertUnprocessable()->assertJsonValidationErrors(['status', 'is_platform_admin', 'can_lend']);

    expect($bank->fresh()->name)->not->toBe('Attack');
    expect($user->fresh()->is_platform_admin)->toBeFalse();
});

it('escapes institution names in the workspace HTML', function () {
    $name = '<script>alert("bank")</script>';
    $bank = Bank::factory()->create(['name' => $name]);
    signInUser(bankStaff(bank: $bank));

    $this->get(route('banks.show', $bank))->assertOk()->assertSee(e($name), false)->assertDontSee($name, false);
});

it('suspends a bank and revokes all of its staff sessions', function () {
    $staff = bankStaff();
    $bank = $staff->membership->bank;
    signInUser(User::factory()->platformAdmin()->create());

    $this->post(route('banks.suspend', $bank), ['reason' => 'Institution requires investigation.'])->assertRedirect();

    expect($staff->fresh()->auth_version)->toBe(1);
    expect($bank->fresh()->status)->toBe(BankStatus::Suspended);
    expect($bank->fresh()->can_borrow)->toBeFalse();
});

it('keeps bank review queries bounded when access requests increase', function () {
    $admin = bankStaff(BankRole::Admin);
    $bank = $admin->membership->bank;
    AccessRequest::factory()->count(2)->create(['bank_id' => $bank->id]);
    signInUser($admin);
    DB::enableQueryLog();

    $this->get(route('banks.show', $bank))->assertOk();
    $initialQueries = count(DB::getQueryLog());
    AccessRequest::factory()->count(18)->create(['bank_id' => $bank->id]);
    DB::flushQueryLog();
    $this->get(route('banks.show', $bank))->assertOk();
    $expandedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expandedQueries)->toBeLessThanOrEqual($initialQueries + 2);
});

it('rejects bank verification by bank staff with 403', function () {
    $admin = bankStaff(BankRole::Admin, Bank::factory()->create());
    signInUser($admin);

    $this->postJson(route('banks.verify', $admin->membership->bank), ['capabilities' => ['borrower'], 'reason' => 'Attempt self verification'])->assertForbidden();

    expect($admin->membership->bank->fresh()->status)->toBe(BankStatus::Pending);
});

it('hides cross bank profile writes with 404', function () {
    $otherBank = Bank::factory()->create();
    signInUser(bankStaff(BankRole::Admin));

    $this->patchJson(route('banks.update', $otherBank), ['name' => 'Unauthorized'])->assertNotFound();

    expect($otherBank->fresh()->name)->not->toBe('Unauthorized');
});

it('renders the platform operations view', function () {
    signInUser(User::factory()->platformAdmin()->create());

    $this->get(route('operations'))->assertOk()->assertSee('Create a bank');
});

it('does not store a usable invitation token in the queued job', function () {
    config(['queue.default' => 'database']);
    app(ManageBankAccess::class)->createBank(User::factory()->platformAdmin()->create(), [
        'name' => 'Synthetic Bank', 'country_code' => 'LK', 'registration_number' => 'SYN-QUEUE-1', 'requested_capabilities' => ['lender'],
        'admin_name' => 'Synthetic Admin', 'admin_email' => 'queued.admin@example.test',
    ]);

    $command = json_decode(DB::table('jobs')->value('payload'), true)['data']['command'];
    preg_match_all('/[A-Za-z0-9]{64}/', decrypt($command, false), $candidates);
    expect(preg_match('/"[A-Za-z0-9]{64}"/', $command))->toBe(0)
        ->and(collect($candidates[0])->map(fn (string $value): string => hash('sha256', $value)))->toContain(BankInvitation::query()->sole()->token_hash);
});

it('keeps verification when a bank profile is saved without changes', function (array $capabilities) {
    $bank = Bank::factory()->verified()->create(['requested_capabilities' => ['borrower', 'lender']]);
    signInUser(bankStaff(BankRole::Admin, $bank));

    $this->patch(route('banks.update', $bank), ['name' => $bank->name, 'country_code' => $bank->country_code, 'registration_number' => $bank->registration_number, 'requested_capabilities' => $capabilities])
        ->assertRedirect()->assertSessionHas('status', 'No profile changes were found. Your verification is unchanged.');

    expect($bank->fresh()->status)->toBe(BankStatus::Verified)->and($bank->fresh()->can_lend)->toBeTrue();
    $this->assertDatabaseMissing('audit_events', ['action' => 'bank.profile_updated']);
})->with([[['borrower', 'lender']], [['lender', 'borrower']]]);

it('returns not found rather than a server error for malformed route identifiers', function (string $method, string $uri) {
    signInUser(bankStaff(BankRole::Admin));

    $this->json($method, $uri)->assertNotFound();
})->with([
    ['GET', '/banks/not-a-number'], ['GET', '/banks/999999999999999999999'], ['GET', '/documents/not-a-number'],
    ['POST', '/notifications/not-a-uuid/read'], ['POST', '/bank/members/not-a-number/reinstatement'],
    ['POST', '/operations/accounts/not-a-number/deactivation'], ['POST', '/access-requests/not-a-number/review'],
]);
