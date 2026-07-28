<?php

use App\BankRole;
use App\FundingRequestStatus;
use App\Models\Bank;
use App\Models\FundingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->freezeTime();
});

it('requires authentication for request APIs with 401', function () {
    $this->getJson(route('api.funding.index'))->assertUnauthorized();
});

it('enforces borrower maker and approver roles at the policy boundary', function (BankRole $role) {
    $user = bankStaff($role);
    $record = fundingDraft($user);
    $gate = Gate::forUser($user);

    expect($gate->allows('create', FundingRequest::class))->toBe($role === BankRole::Maker);
    expect($gate->allows('view', $record))->toBeTrue();
    expect($gate->allows('update', $record))->toBe($role === BankRole::Maker);
})->with(BankRole::cases());

it('does not let bank admins publish treasury requests with 403', function () {
    $admin = bankStaff(BankRole::Admin);
    signInUser($admin);

    $this->postJson(route('api.funding.store'), fundingPayload())->assertForbidden();

    $this->assertDatabaseCount('funding_requests', 0);
});

it('does not let an unverified bank create requests with 403', function () {
    signInUser(bankStaff(bank: Bank::factory()->create()));

    $this->postJson(route('api.funding.store'), fundingPayload())->assertForbidden();
});

it('requires approved borrower capability with 403', function () {
    signInUser(bankStaff(bank: Bank::factory()->verified()->create(['can_borrow' => false])));

    $this->postJson(route('api.funding.store'), fundingPayload())->assertForbidden();
});

it('hides private drafts in another bank with 404', function () {
    $record = FundingRequest::factory()->create();
    signInUser(bankStaff());

    $this->getJson(route('api.funding.show', $record))->assertNotFound();
    $this->get(route('funding.show', $record))->assertNotFound();
});

it('shows eligible lenders only published open requests from other banks', function () {
    $open = FundingRequest::factory()->published()->create(['title' => 'Visible funding']);
    FundingRequest::factory()->create(['title' => 'Private draft']);
    FundingRequest::factory()->published()->create(['title' => 'Expired window', 'bidding_deadline' => now()]);
    FundingRequest::factory()->published()->for(Bank::factory()->suspended())->create(['title' => 'Suspended borrower']);
    $lender = bankStaff();
    FundingRequest::factory()->published()->create(['bank_id' => $lender->membership->bank_id, 'title' => 'Own institution']);
    signInUser($lender);

    $this->getJson(route('api.marketplace'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
});

it('limits lender browsing to treasury makers and approvers', function (BankRole $role, bool $allowed) {
    $user = bankStaff($role);

    expect(Gate::forUser($user)->allows('viewMarketplace', FundingRequest::class))->toBe($allowed);
})->with([
    'maker' => [BankRole::Maker, true], 'approver' => [BankRole::Approver, true],
    'admin only' => [BankRole::Admin, false], 'auditor only' => [BankRole::Auditor, false],
]);

it('does not grant platform staff treasury or financial document access', function () {
    $record = FundingRequest::factory()->published()->create();
    signInUser(User::factory()->platformAdmin()->create());

    $this->getJson(route('api.funding.show', $record))->assertNotFound();
    $this->getJson(route('api.marketplace'))->assertForbidden();
});

it('excludes internal approvals and actors from lender responses', function () {
    $record = FundingRequest::factory()->published()->create();
    signInUser(bankStaff());

    $this->getJson(route('api.funding.show', $record))->assertOk()
        ->assertJsonPath('data.borrower.name', $record->bank->name)
        ->assertJsonMissingPath('data.version')->assertJsonMissingPath('data.pendingApproval')
        ->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.contributor_ids')
        ->assertJsonMissingPath('data.can');
});

it('blocks expired request access before the expiry command runs with 404', function () {
    $record = FundingRequest::factory()->published()->create(['bidding_deadline' => now()]);
    signInUser(bankStaff());

    $this->getJson(route('api.funding.show', $record))->assertNotFound();

    expect($record->fresh()->status)->toBe(FundingRequestStatus::Open);
});

it('keeps published terms frozen when the maker tries to edit them with 403', function () {
    $maker = bankStaff();
    $record = fundingDraft($maker, ['status' => FundingRequestStatus::Open]);
    signInUser($maker);

    $this->patchJson(route('api.funding.update', $record), fundingPayload(['amount' => '1.00']))->assertForbidden();

    expect($record->fresh()->amount)->toBe('250000000.00');
});

it('escapes borrower background in lender HTML', function () {
    $background = '<script>alert("borrower")</script> financial history';
    $record = FundingRequest::factory()->published()->create(['borrower_background' => $background]);
    signInUser(bankStaff());

    $this->get(route('funding.show', $record))->assertOk()->assertSee(e($background), false)->assertDontSee($background, false);
});

it('keeps marketplace queries bounded as requests increase', function () {
    FundingRequest::factory()->published()->count(2)->create();
    signInUser(bankStaff());
    DB::enableQueryLog();
    $this->get(route('marketplace'))->assertOk();
    $initial = count(DB::getQueryLog());
    FundingRequest::factory()->published()->count(12)->create();
    DB::flushQueryLog();

    $this->get(route('marketplace'))->assertOk();
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expanded)->toBeLessThanOrEqual($initial + 2);
});

it('renders the funding creation and draft editing forms for a maker', function () {
    $maker = bankStaff();
    $record = fundingDraft($maker);
    signInUser($maker);

    $this->get(route('funding.create'))->assertOk()->assertSee('Create a funding request.');
    $this->get(route('funding.edit', $record))->assertOk()->assertSee('Refine your request.');
});

it('keeps the published borrower profile unchanged after a bank profile edit', function () {
    $record = FundingRequest::factory()->published()->create();
    $name = $record->borrower_profile['name'];
    $record->bank->update(['name' => 'Different current bank name']);
    signInUser(bankStaff());

    $this->getJson(route('api.funding.show', $record))->assertOk()->assertJsonPath('data.borrower.name', $name);
});

it('requires approved lender capability for marketplace and published details', function () {
    $record = FundingRequest::factory()->published()->create();
    signInUser(bankStaff(bank: Bank::factory()->verified()->create(['can_lend' => false])));

    $this->getJson(route('api.marketplace'))->assertForbidden();
    $this->getJson(route('api.funding.show', $record))->assertNotFound();
});
