<?php

use App\Actions\ManageOffers;
use App\BankRole;
use App\BankStatus;
use App\FundingRequestStatus;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->freezeTime();
});

it('requires authentication for lender offer APIs with 401', function () {
    $this->getJson(route('api.offers.index'))->assertUnauthorized();
});

it('creates an exact rate offer and publishes it only after independent lender approval', function () {
    $maker = bankStaff();
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    $request = FundingRequest::factory()->published()->create();
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);
    $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request))->assertCreated()->assertJsonPath('data.rate', '8.1250')->assertJsonPath('data.status', 'draft');
    $offer = Offer::query()->sole();
    expect($offer->rate_units)->toBe(81250);
    $this->postJson(route('api.offers.submit', $offer))->assertOk()->assertJsonPath('data.status', 'pending_approval');
    signInUser($approver);
    $this->postJson(route('api.offers.review', ['offer' => $offer, 'approval' => OfferApproval::query()->sole()->id]), ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'active');
    $this->assertDatabaseHas('offers', ['id' => $offer->id, 'active_slot' => $maker->membership->bank_id, 'label_number' => 1]);
    $this->assertDatabaseHas('audit_events', ['action' => 'offer.activated', 'bank_id' => $maker->membership->bank_id]);
});

it('enforces the own offer role matrix without treating bank admin as treasury', function (BankRole $role) {
    $user = bankStaff($role);
    $offer = Offer::factory()->pending()->create(['bank_id' => $user->membership->bank_id]);
    OfferApproval::factory()->create(['offer_id' => $offer->id]);
    $gate = Gate::forUser($user);

    expect($gate->allows('view', $offer))->toBeTrue();
    expect($gate->allows('create', [Offer::class, $offer->fundingRequest]))->toBe($role === BankRole::Maker);
    expect($gate->allows('update', $offer))->toBe($role === BankRole::Maker);
    expect($gate->allows('review', $offer))->toBe($role === BankRole::Approver);
})->with(BankRole::cases());

it('hides lender offers from competitors and the borrower with 404', function () {
    $borrower = bankStaff();
    $request = fundingDraft($borrower, ['status' => FundingRequestStatus::Open]);
    $offer = marketOffer(bankStaff(), $request);
    signInUser(bankStaff());
    $this->getJson(route('api.offers.show', $offer))->assertNotFound();
    $this->postJson(route('api.offers.submit', $offer))->assertNotFound();
    signInUser($borrower);
    $this->getJson(route('api.offers.show', $offer))->assertNotFound();
});

it('prevents a dual capability bank from bidding on its own request with 403', function () {
    $maker = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request))->assertForbidden();
    $this->assertDatabaseCount('offers', 0);
});

it('hides marketplace requests from users without approved lender capability with 404', function () {
    $maker = bankStaff();
    $maker->membership->bank->forceFill(['can_lend' => false])->save();
    $request = FundingRequest::factory()->published()->create();
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request))->assertNotFound();
});

it('requires own approved instructions before drafting an offer with 422', function (bool $foreign) {
    $maker = bankStaff();
    $request = FundingRequest::factory()->published()->create();
    $instruction = $foreign ? SettlementInstruction::factory()->approved()->create() : SettlementInstruction::factory()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request))->assertUnprocessable()->assertJsonValidationErrors('settlement_instruction_id');
    $this->assertDatabaseCount('offers', 0);
})->with(['foreign version' => true, 'pending version' => false]);

it('rejects unsupported rates structured conditions and forged fields with 422', function (array $overrides, string $field) {
    $maker = bankStaff();
    $request = FundingRequest::factory()->published()->create();
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request, $overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('offers', 0);
})->with([
    'numeric float' => [['rate' => 8.125], 'rate'], 'negative' => [['rate' => '-1'], 'rate'],
    'rate over cap' => [['rate' => '100.0001'], 'rate'], 'excess decimals' => [['rate' => '8.12345'], 'rate'],
    'exponent' => [['rate' => '8e1'], 'rate'], 'unsupported collateral' => [['collateral' => 'call my bank'], 'collateral'],
    'unsupported payment' => [['interest_payment' => 'custom'], 'interest_payment'],
    'forged bank' => [['bank_id' => 999], 'bank_id'], 'forged status' => [['status' => 'active'], 'status'],
    'forged token' => [['borrower_token' => 'fake'], 'borrower_token'], 'partial amount' => [['amount' => '1.00'], 'amount'],
    'lender free text' => [['notes' => 'Contact my bank directly'], 'notes'],
]);

it('preserves permitted rate boundaries without floating point arithmetic', function (string $rate, string $expected) {
    $maker = bankStaff();
    $request = FundingRequest::factory()->published()->create();
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request, ['rate' => $rate]))->assertCreated()->assertJsonPath('data.rate', $expected);
})->with(['zero' => ['0', '0.0000'], 'upper cap' => ['100.0000', '100.0000'], 'small fraction' => ['0.0001', '0.0001']]);

it('requires offer validity to cover bidding and stay within settlement day with 422', function (string $boundary) {
    $maker = bankStaff();
    $request = FundingRequest::factory()->published()->create();
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    $deadline = match ($boundary) {
        'past' => now()->subMinute(), 'before bids close' => $request->bidding_deadline->subSecond(),
        default => $request->settlement_date->addDay(),
    };
    signInUser($maker);

    $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request, ['valid_until' => $deadline->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors('valid_until');
})->with(['past', 'before bids close', 'after settlement']);

it('blocks offer self approval and approval by another draft contributor with 403', function () {
    $maker = bankStaff();
    $offer = Offer::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    $maker->membership->update(['roles' => ['treasury_maker', 'treasury_approver']]);
    $contributor = bankStaff(BankRole::Approver, $maker->membership->bank);
    $offer->forceFill(['contributor_ids' => [$maker->id, $contributor->id]])->save();
    app(ManageOffers::class)->submit($maker->fresh(), $offer);
    $approval = OfferApproval::query()->sole();
    signInUser($maker->fresh());
    $this->postJson(route('api.offers.review', ['offer' => $offer, 'approval' => $approval]), ['decision' => 'approve'])->assertForbidden();
    signInUser($contributor);
    $this->postJson(route('api.offers.review', ['offer' => $offer, 'approval' => $approval]), ['decision' => 'approve'])->assertForbidden();
});

it('supersedes an offer approval when terms change and refuses replay with 409', function () {
    $maker = bankStaff();
    $offer = Offer::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    app(ManageOffers::class)->submit($maker, $offer);
    $approval = OfferApproval::query()->sole();
    signInUser($maker);
    $this->patchJson(route('api.offers.update', $offer), offerPayload($offer->settlementInstruction, $offer->fundingRequest, ['rate' => '7.9999']))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft');
    expect($approval->fresh()->status)->toBe('superseded');
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));
    $this->postJson(route('api.offers.review', ['offer' => $offer, 'approval' => $approval]), ['decision' => 'approve'])->assertConflict();
});

it('requires withdrawal before activating a replacement with 409', function () {
    $maker = bankStaff();
    $request = FundingRequest::factory()->published()->create();
    $active = marketOffer($maker, $request);
    $replacement = Offer::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id, 'funding_request_id' => $request->id]);
    app(ManageOffers::class)->submit($maker, $replacement);
    $approval = OfferApproval::query()->sole();
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    signInUser($approver);
    $this->postJson(route('api.offers.review', ['offer' => $replacement, 'approval' => $approval]), ['decision' => 'approve'])->assertConflict();
    signInUser($maker);
    $this->postJson(route('api.offers.withdraw', $active), ['reason' => 'Replacing financial terms.'])->assertOk()->assertJsonPath('data.status', 'withdrawn');
    signInUser($approver);
    $this->postJson(route('api.offers.review', ['offer' => $replacement, 'approval' => $approval]), ['decision' => 'approve'])->assertOk();
    expect($replacement->fresh()->label_number)->toBe(2);
    expect($replacement->fresh()->borrower_token)->not->toBe($active->borrower_token);
});

it('enforces one active offer per lender and request in the database', function () {
    $maker = bankStaff();
    $request = FundingRequest::factory()->published()->create();
    marketOffer($maker, $request);

    expect(fn () => marketOffer($maker, $request))->toThrow(QueryException::class);
});

it('publishes only safe anonymous fields and excludes drafts suspended lenders and competitor access', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $lender = bankStaff();
    $lender->membership->bank->update(['name' => 'Hidden Lender Alpha']);
    $offer = marketOffer($lender, $request);
    Offer::factory()->create(['funding_request_id' => $request->id]);
    $hidden = marketOffer(bankStaff(), $request);
    $hidden->bank->forceFill(['status' => BankStatus::Suspended])->save();
    signInUser($borrower);
    $response = $this->getJson(route('api.funding.offers', $request))->assertOk()->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.label', 'Offer 1');
    expect(array_keys($response->json('data.offers.0')))->toBe(['token', 'label', 'rate', 'collateral', 'interestPayment', 'validUntil']);
    expect($response->getContent())->not->toContain('Hidden Lender Alpha')->not->toContain($offer->id)->not->toContain($offer->settlement_instruction_id)->not->toContain('treasury@synthetic.example');
    $this->get(route('funding.offers', $request))->assertOk()->assertDontSee('Hidden Lender Alpha')->assertDontSee($offer->id);
    signInUser($lender);
    $this->getJson(route('api.funding.offers', $request))->assertNotFound();
});

it('hides an active offer after its approved bank identity becomes stale', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    $offer->bank->update(['name' => 'New lender identity']);
    signInUser($borrower);

    $this->getJson(route('api.funding.offers', $request))->assertOk()->assertJsonCount(0, 'data.offers');
});

it('expires unselected offers once and preserves selected reservations', function () {
    $expired = Offer::factory()->active()->create(['valid_until' => now()]);
    $selected = Offer::factory()->active()->create(['status' => OfferStatus::Selected, 'valid_until' => now()]);

    $this->artisan('offers:expire')->expectsOutput('Expired 1 offer(s).')->assertSuccessful();
    $this->artisan('offers:expire')->expectsOutput('Expired 0 offer(s).')->assertSuccessful();
    expect($expired->fresh()->status)->toBe(OfferStatus::Expired);
    expect($expired->fresh()->active_slot)->toBeNull();
    expect($selected->fresh()->status)->toBe(OfferStatus::Selected);
    $this->assertDatabaseCount('audit_events', 1);
});

it('keeps borrower comparison queries bounded as eligible offers increase', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    marketOffer(bankStaff(), $request);
    signInUser($borrower);
    DB::enableQueryLog();
    $this->getJson(route('api.funding.offers', $request))->assertOk();
    $initial = count(DB::getQueryLog());
    for ($index = 0; $index < 8; $index++) {
        marketOffer(bankStaff(), $request);
    }
    DB::flushQueryLog();
    $this->getJson(route('api.funding.offers', $request))->assertOk()->assertJsonCount(9, 'data.offers');
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expanded)->toBeLessThanOrEqual($initial + 2);
});

it('renders lender draft create edit and review screens', function () {
    $maker = bankStaff();
    $offer = Offer::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    signInUser($maker);

    $this->get(route('offers.create', $offer->fundingRequest))->assertOk()->assertSee('Prepare a liquidity offer.');
    $this->get(route('offers.edit', $offer))->assertOk()->assertSee('Refine your offer.');
    $this->get(route('offers.show', $offer))->assertOk()->assertSee('Submit offer for approval');
});

it('does not let a bank admin submit an owned treasury offer with 403', function () {
    $admin = bankStaff(BankRole::Admin);
    $offer = Offer::factory()->create(['bank_id' => $admin->membership->bank_id]);
    signInUser($admin);

    $this->postJson(route('api.offers.submit', $offer))->assertForbidden();
    $this->assertDatabaseCount('offer_approvals', 0);
});

it('requires a rejection reason and refuses a completed offer review replay with 409', function () {
    $maker = bankStaff();
    $offer = Offer::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    app(ManageOffers::class)->submit($maker, $offer);
    $approval = OfferApproval::query()->sole();
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));
    $route = route('api.offers.review', ['offer' => $offer, 'approval' => $approval]);
    $this->postJson($route, ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson($route, ['decision' => 'reject', 'reason' => 'Collateral terms require revision.'])->assertOk()->assertJsonPath('data.status', 'draft');
    expect($approval->fresh()->status)->toBe('rejected');
    $this->postJson($route, ['decision' => 'approve'])->assertConflict();
});

it('rechecks request cutoff at lender approval before the expiry job runs with 409', function () {
    $maker = bankStaff();
    $offer = Offer::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    app(ManageOffers::class)->submit($maker, $offer);
    $offer->fundingRequest->forceFill(['bidding_deadline' => now()])->save();
    signInUser(bankStaff(BankRole::Approver, $maker->membership->bank));

    $this->postJson(route('api.offers.review', ['offer' => $offer, 'approval' => OfferApproval::query()->sole()]), ['decision' => 'approve'])->assertConflict();
    expect($offer->fresh()->active_slot)->toBeNull();
});

it('excludes settlement instruction references from an owned auditor offer response', function () {
    $auditor = bankStaff(BankRole::Auditor);
    $offer = Offer::factory()->active()->create(['bank_id' => $auditor->membership->bank_id]);
    signInUser($auditor);

    $this->getJson(route('api.offers.show', $offer))->assertOk()->assertJsonMissingPath('data.instructionId')->assertJsonMissingPath('data.instructionVersion');
    $this->get(route('offers.show', $offer))->assertOk()->assertDontSee('Review approved instructions')->assertDontSee('DEMO000012345678');
});

it('escapes internal lender review notes in owned offer history', function () {
    $maker = bankStaff();
    $offer = Offer::factory()->create(['bank_id' => $maker->membership->bank_id]);
    $dangerous = '<script>alert("review")</script>';
    OfferApproval::factory()->create(['offer_id' => $offer->id, 'status' => 'rejected', 'reason' => $dangerous]);
    signInUser($maker);

    $this->get(route('offers.show', $offer))->assertOk()->assertSee(e($dangerous), false)->assertDontSee($dangerous, false);
});

it('rejects a stale actor session during locked offer revalidation', function () {
    $maker = bankStaff();
    $offer = Offer::factory()->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    User::query()->whereKey($maker->id)->increment('auth_version');

    expect(fn () => app(ManageOffers::class)->submit($maker, $offer))->toThrow(HttpException::class, 'Please sign in again.');
    expect($offer->fresh()->status)->toBe(OfferStatus::Draft);
    $this->assertDatabaseCount('offer_approvals', 0);
});
