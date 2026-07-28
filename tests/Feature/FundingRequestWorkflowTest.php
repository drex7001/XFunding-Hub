<?php

use App\Actions\ManageFundingRequests;
use App\BankRole;
use App\FundingRequestStatus;
use App\Models\FundingRequest;
use App\Models\FundingRequestApproval;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->freezeTime();
});

it('creates a bank owned draft with an exact wholesale amount', function () {
    $maker = bankStaff();
    signInUser($maker);

    $response = $this->postJson(route('api.funding.store'), fundingPayload(['amount' => '999999999999.99']))
        ->assertCreated()->assertJsonPath('data.amount', '999999999999.99')->assertJsonPath('data.status', 'draft');

    $this->assertDatabaseHas('funding_requests', ['id' => $response->json('data.id'), 'bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id, 'amount_minor' => 99999999999999]);
    $response->assertHeader('Location', route('api.funding.show', $response->json('data.id')));
});

it('rejects invalid financial terms with 422 and no draft', function (array $overrides, string $field) {
    signInUser(bankStaff());

    $this->postJson(route('api.funding.store'), fundingPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('funding_requests', 0);
})->with([
    'zero amount' => [['amount' => '0.00'], 'amount'],
    'negative amount' => [['amount' => '-1.00'], 'amount'],
    'excess precision' => [['amount' => '1.001'], 'amount'],
    'amount overflow' => [['amount' => '1000000000000.00'], 'amount'],
    'float instead of decimal string' => [['amount' => 100.25], 'amount'],
    'exponential notation' => [['amount' => '1e6'], 'amount'],
    'unsupported currency' => [['currency' => 'JPY'], 'currency'],
    'zero tenor' => [['tenor_days' => 0], 'tenor_days'],
    'too long tenor' => [['tenor_days' => 3651], 'tenor_days'],
    'unknown day count' => [['day_count' => 'UNKNOWN'], 'day_count'],
    'short background' => [['borrower_background' => 'none'], 'borrower_background'],
    'injected bank id' => [['bank_id' => 999], 'bank_id'],
    'injected status' => [['status' => 'open'], 'status'],
]);

it('normalizes offset bidding timestamps to UTC', function () {
    $this->travelTo('2026-10-20 09:00:00 UTC');
    signInUser(bankStaff());

    $this->postJson(route('api.funding.store'), fundingPayload(['settlement_date' => '2026-10-22', 'bidding_deadline' => '2026-10-21T15:30:00+05:30']))
        ->assertCreated()->assertJsonPath('data.biddingDeadline', '2026-10-21T10:00:00+00:00');

    $this->assertDatabaseHas('funding_requests', ['bidding_deadline' => '2026-10-21 10:00:00']);
});

it('allows same day settlement with a future bidding deadline', function () {
    $this->travelTo('2026-10-20 09:00:00 UTC');
    signInUser(bankStaff());

    $this->postJson(route('api.funding.store'), fundingPayload(['settlement_date' => '2026-10-20', 'bidding_deadline' => '2026-10-20T12:00:00Z']))->assertCreated();
});

it('rejects a bidding date later than settlement with 422', function () {
    $this->travelTo('2026-10-20 09:00:00 UTC');
    signInUser(bankStaff());

    $this->postJson(route('api.funding.store'), fundingPayload(['settlement_date' => '2026-10-21', 'bidding_deadline' => '2026-10-22T12:00:00Z']))->assertUnprocessable()->assertJsonValidationErrors('bidding_deadline');
});

it('requires a financial document before publication submission with 422', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    signInUser($maker);

    $this->postJson(route('api.funding.submit', $record))->assertUnprocessable()->assertJsonValidationErrors('documents');

    expect($record->fresh()->status)->toBe(FundingRequestStatus::Draft);
    $this->assertDatabaseCount('funding_request_approvals', 0);
});

it('publishes only after an independent borrower approver reviews the current revision', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    $record = fundingDraft($maker);
    financialEvidence($record);
    signInUser($maker);
    $this->postJson(route('api.funding.submit', $record))->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $approval = $record->approvals()->sole();
    signInUser($approver);

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $approval]), ['decision' => 'approve'])
        ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.borrower.name', $maker->membership->bank->name);

    expect($record->fresh()->published_at)->not->toBeNull();
    $this->assertDatabaseHas('funding_request_approvals', ['id' => $approval->id, 'status' => 'approved', 'reviewed_by' => $approver->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'funding.published', 'bank_id' => $record->bank_id]);
});

it('rejects publication approval by a document contributor with 403', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $contributor = bankStaff(BankRole::Maker, $maker->membership->bank);
    $contributor->membership->update(['roles' => ['treasury_maker', 'treasury_approver']]);
    $record = fundingDraft($maker);
    $action = app(ManageFundingRequests::class);
    $action->addDocument($contributor, $record, UploadedFile::fake()->create('contributor.pdf', 10, 'application/pdf'));
    $action->submit($maker, $record);
    signInUser($contributor);

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $record->approvals()->sole()]), ['decision' => 'approve'])->assertForbidden();

    expect($record->fresh()->status)->toBe(FundingRequestStatus::PendingApproval);
});

it('supersedes publication approval when a maker edits its terms', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    financialEvidence($record);
    app(ManageFundingRequests::class)->submit($maker, $record);
    $approval = $record->approvals()->sole();
    signInUser($maker);

    $this->patchJson(route('api.funding.update', $record), fundingPayload(['amount' => '300000000.01']))->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.amount', '300000000.01')->assertJsonPath('data.pendingApproval', null);

    expect($approval->fresh()->status)->toBe('superseded');
    expect($record->fresh()->version)->toBe(2);
});

it('refuses a stale approval id after an updated draft is resubmitted with 409', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    $record = fundingDraft($maker);
    financialEvidence($record);
    $action = app(ManageFundingRequests::class);
    $action->submit($maker, $record);
    $old = $record->approvals()->sole();
    $action->update($maker, $record, fundingPayload());
    $action->submit($maker, $record);
    signInUser($approver);

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $old]), ['decision' => 'approve'])->assertConflict();

    expect($record->fresh()->status)->toBe(FundingRequestStatus::PendingApproval);
    $this->assertDatabaseMissing('audit_events', ['action' => 'funding.published']);
});

it('requires a new approval if the bank identity changed after submission with 409', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    $record = fundingDraft($maker);
    financialEvidence($record);
    app(ManageFundingRequests::class)->submit($maker, $record);
    $maker->membership->bank->update(['name' => 'Changed institution name']);
    signInUser($approver);

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $record->approvals()->sole()]), ['decision' => 'approve'])->assertConflict();

    expect($record->fresh()->status)->toBe(FundingRequestStatus::PendingApproval);
});

it('returns a rejected publication to draft with its review reason', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    financialEvidence($record);
    app(ManageFundingRequests::class)->submit($maker, $record);
    $approval = $record->approvals()->sole();
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $approval]), ['decision' => 'reject', 'reason' => 'Please update the financial report.'])->assertOk()->assertJsonPath('data.status', 'draft');

    expect($approval->fresh()->reason)->toBe('Please update the financial report.');
});

it('keeps a published request open until cancellation is independently approved', function () {
    $maker = bankStaff();
    $record = fundingDraft($maker, ['status' => FundingRequestStatus::Open, 'published_at' => now()]);
    signInUser($maker);
    $this->postJson(route('api.funding.cancel', $record), ['reason' => 'Liquidity requirement was withdrawn.'])->assertOk()->assertJsonPath('data.status', 'open');
    $approval = $record->approvals()->sole();
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $approval]), ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($record->fresh()->cancelled_at)->not->toBeNull();
});

it('expires open and pending requests idempotently with a system audit event', function () {
    $open = FundingRequest::factory()->published()->create(['bidding_deadline' => now()]);
    $pending = FundingRequest::factory()->pending()->create(['bidding_deadline' => now()]);
    $approval = FundingRequestApproval::factory()->for($pending)->create();
    $draft = FundingRequest::factory()->create(['bidding_deadline' => now()->subHour()]);

    $this->artisan('funding:expire')->expectsOutput('Expired 2 funding request(s).')->assertSuccessful();

    expect($open->fresh()->status)->toBe(FundingRequestStatus::Expired);
    expect($pending->fresh()->status)->toBe(FundingRequestStatus::Expired);
    expect($approval->fresh()->status)->toBe('superseded');
    expect($draft->fresh()->status)->toBe(FundingRequestStatus::Draft);
    $this->assertDatabaseHas('audit_events', ['action' => 'funding.expired', 'actor_id' => null]);
    $this->artisan('funding:expire')->expectsOutput('Expired 0 funding request(s).')->assertSuccessful();
    $this->assertDatabaseCount('audit_events', 2);
});

it('normalizes positive decimal amounts without floating point arithmetic', function (string $amount, string $formatted, int $minor) {
    signInUser(bankStaff());

    $response = $this->postJson(route('api.funding.store'), fundingPayload(['amount' => $amount]))->assertCreated()->assertJsonPath('data.amount', $formatted);

    $this->assertDatabaseHas('funding_requests', ['id' => $response->json('data.id'), 'amount_minor' => $minor]);
})->with(['one minor unit' => ['0.01', '0.01', 1], 'one decimal place' => ['1.2', '1.20', 120]]);

it('rejects past financial dates with 422', function (array $dates, string $field) {
    $this->travelTo('2026-10-20 09:00:00 UTC');
    signInUser(bankStaff());

    $this->postJson(route('api.funding.store'), fundingPayload($dates))->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('funding_requests', 0);
})->with([
    'deadline at now' => [['bidding_deadline' => '2026-10-20T09:00:00Z'], 'bidding_deadline'],
    'past settlement' => [['settlement_date' => '2026-10-19'], 'settlement_date'],
]);

it('refuses publication after the deadline without waiting for the expiry job with 409', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    financialEvidence($record);
    app(ManageFundingRequests::class)->submit($maker, $record);
    $record->forceFill(['bidding_deadline' => now()])->save();
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $record->approvals()->sole()]), ['decision' => 'approve'])->assertConflict();

    expect($record->fresh()->published_at)->toBeNull();
});

it('rejects replaying a completed publication approval with 409', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $checker = bankStaff(BankRole::Approver, $maker->membership->bank);
    $record = fundingDraft($maker);
    financialEvidence($record);
    $action = app(ManageFundingRequests::class);
    $action->submit($maker, $record);
    $approval = $record->approvals()->sole();
    $action->review($checker, $record, $approval, true, null);
    signInUser($checker);

    $this->postJson(route('api.funding.review', ['fundingRequest' => $record, 'approval' => $approval]), ['decision' => 'approve'])->assertConflict();

    $this->assertDatabaseCount('funding_request_approvals', 1);
    expect($record->fresh()->status)->toBe(FundingRequestStatus::Open);
});
