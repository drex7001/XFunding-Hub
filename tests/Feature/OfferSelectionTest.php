<?php

use App\Actions\ManageFundingRequests;
use App\Actions\ManageOffers;
use App\Actions\SelectOffer;
use App\BankRole;
use App\BankStatus;
use App\FundingRequestStatus;
use App\Models\Bank;
use App\Models\Deal;
use App\Models\FundingDocument;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\SelectionProposal;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use Database\Seeders\DemoBankSeeder;
use Database\Seeders\DemoMarketplaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->freezeTime();
});

it('reserves one anonymous winner and closes every losing offer after independent selection approval', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $winnerMaker = bankStaff();
    $winnerMaker->membership->bank->update(['name' => 'Secret Winning Lender']);
    $winner = marketOffer($winnerMaker, $request);
    $loser = marketOffer(bankStaff(), $request);
    $pending = Offer::factory()->pending()->create(['funding_request_id' => $request->id]);
    $pendingApproval = OfferApproval::factory()->create(['offer_id' => $pending->id]);
    signInUser($borrower);
    $proposalId = $this->postJson(route('api.selection.store', $request), ['offer_token' => $winner->borrower_token])->assertCreated()->json('data.id');
    expect($request->fresh()->status)->toBe(FundingRequestStatus::Open);
    signInUser(bankStaff(BankRole::Approver, $borrower->membership->bank));
    $response = $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposalId]), ['decision' => 'approve'])
        ->assertOk()->assertJsonPath('data.status', 'awaiting_fee')->assertJsonPath('data.identityUnlocked', false)
        ->assertJsonPath('data.feeConfigured', false)->assertJsonPath('data.terms.rate', '8.1250')->assertJsonPath('data.terms.label', 'Offer 1');
    expect($response->getContent())->not->toContain('Secret Winning Lender')->not->toContain($winner->id)->not->toContain($winner->settlement_instruction_id)->not->toContain('DEMO000012345678');
    expect($request->fresh()->status)->toBe(FundingRequestStatus::Selected);
    expect($winner->fresh()->status)->toBe(OfferStatus::Selected);
    expect($loser->fresh()->status)->toBe(OfferStatus::Unsuccessful);
    expect($loser->fresh()->active_slot)->toBeNull();
    expect($pending->fresh()->status)->toBe(OfferStatus::Unsuccessful);
    expect($pendingApproval->fresh()->status)->toBe('superseded');
    $deal = Deal::query()->sole();
    expect($deal->instruction_snapshot['details']['account_number'])->toBe('DEMO000012345678');
    expect(DB::table('deals')->value('instruction_snapshot'))->not->toContain('DEMO000012345678');
    $this->assertDatabaseHas('audit_events', ['action' => 'selection.approved', 'bank_id' => $borrower->membership->bank_id]);
});

it('enforces borrower selection roles at the policy boundary', function (BankRole $role) {
    $user = bankStaff($role);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $user->membership->bank_id]);
    $proposal = SelectionProposal::factory()->create(['funding_request_id' => $request->id, 'offer_id' => marketOffer(bankStaff(), $request)->id]);
    $gate = Gate::forUser($user);

    expect($gate->allows('viewAny', [SelectionProposal::class, $request]))->toBeTrue();
    expect($gate->allows('create', [SelectionProposal::class, $request]))->toBe($role === BankRole::Maker);
    expect($gate->allows('review', $proposal))->toBe($role === BankRole::Approver);
})->with(BankRole::cases());

it('rejects selection self approval even with combined roles with 403', function () {
    $borrower = bankStaff();
    $borrower->membership->update(['roles' => ['treasury_maker', 'treasury_approver']]);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    $proposal = app(SelectOffer::class)->propose($borrower->fresh(), $request, $offer->borrower_token);
    signInUser($borrower->fresh());

    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'approve'])->assertForbidden();
    $this->assertDatabaseCount('deals', 0);
});

it('rejects a second pending selection and replayed completed approval with 409', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $first = marketOffer(bankStaff(), $request);
    $second = marketOffer(bankStaff(), $request);
    $proposal = app(SelectOffer::class)->propose($borrower, $request, $first->borrower_token);
    signInUser($borrower);
    $this->postJson(route('api.selection.store', $request), ['offer_token' => $second->borrower_token])->assertConflict();
    signInUser(bankStaff(BankRole::Approver, $borrower->membership->bank));
    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'approve'])->assertOk();
    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'approve'])->assertConflict();
    $this->assertDatabaseCount('deals', 1);
});

it('enforces a single pending selection slot in the database', function () {
    $request = FundingRequest::factory()->published()->create();
    $first = marketOffer(bankStaff(), $request);
    $second = marketOffer(bankStaff(), $request);
    SelectionProposal::factory()->create(['funding_request_id' => $request->id, 'offer_id' => $first->id]);

    expect(fn () => SelectionProposal::factory()->create(['funding_request_id' => $request->id, 'offer_id' => $second->id]))->toThrow(QueryException::class);
});

it('hides selection comparison and approvals from unrelated banks and platform staff with 404', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $proposal = app(SelectOffer::class)->propose($borrower, $request, marketOffer(bankStaff(), $request)->borrower_token);
    signInUser(bankStaff(BankRole::Approver));
    $this->getJson(route('api.funding.offers', $request))->assertNotFound();
    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'approve'])->assertNotFound();
    signInUser(User::factory()->platformAdmin()->create());
    $this->getJson(route('api.funding.offers', $request))->assertNotFound();
});

it('does not accept an anonymous token from a different request with 404', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $foreignOffer = Offer::factory()->active()->create();
    signInUser($borrower);

    $this->postJson(route('api.selection.store', $request), ['offer_token' => $foreignOffer->borrower_token])->assertNotFound();
    $this->assertDatabaseCount('selection_proposals', 0);
});

it('supersedes selection when its lender withdraws and refuses approval with 409', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $lender = bankStaff();
    $offer = marketOffer($lender, $request);
    $proposal = app(SelectOffer::class)->propose($borrower, $request, $offer->borrower_token);
    app(ManageOffers::class)->withdraw($lender, $offer, 'Capacity no longer available.');
    signInUser(bankStaff(BankRole::Approver, $borrower->membership->bank));

    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'approve'])->assertConflict();
    expect($proposal->fresh()->status)->toBe('superseded');
    expect($proposal->fresh()->pending_slot)->toBeNull();
    $this->assertDatabaseCount('deals', 0);
});

it('refuses selection after eligibility or approved terms change with 409', function (string $change) {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    $proposal = app(SelectOffer::class)->propose($borrower, $request, $offer->borrower_token);
    match ($change) {
        'lender suspended' => $offer->bank->forceFill(['status' => BankStatus::Suspended])->save(),
        'capability removed' => $offer->bank->forceFill(['can_lend' => false])->save(),
        'profile changed' => $offer->bank->update(['name' => 'Changed lender identity']),
        'offer version changed' => $offer->forceFill(['version' => 2])->save(),
        'offer expired' => $offer->forceFill(['valid_until' => now()])->save(),
        default => $request->forceFill(['bidding_deadline' => now()])->save(),
    };
    signInUser(bankStaff(BankRole::Approver, $borrower->membership->bank));

    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'approve'])->assertConflict();
    $this->assertDatabaseCount('deals', 0);
    expect($request->fresh()->status)->toBe(FundingRequestStatus::Open);
})->with(['lender suspended', 'capability removed', 'profile changed', 'offer version changed', 'offer expired', 'bidding expired']);

it('requires a rejection reason and permits a fresh selection after rejection', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    $proposal = app(SelectOffer::class)->propose($borrower, $request, $offer->borrower_token);
    signInUser(bankStaff(BankRole::Approver, $borrower->membership->bank));
    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'reject', 'reason' => 'Review the collateral requirement.'])->assertOk()->assertJsonPath('data.status', 'rejected');
    signInUser($borrower);
    $this->postJson(route('api.selection.store', $request), ['offer_token' => $offer->borrower_token])->assertCreated();
    $this->assertDatabaseCount('deals', 0);
});

it('prevents cancellation and selection proposals from racing with 409', function (bool $selectFirst) {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    signInUser($borrower);
    if ($selectFirst) {
        app(SelectOffer::class)->propose($borrower, $request, $offer->borrower_token);
        $this->postJson(route('api.funding.cancel', $request), ['reason' => 'Liquidity requirement changed.'])->assertConflict();
    } else {
        app(ManageFundingRequests::class)->proposeCancellation($borrower, $request, 'Liquidity requirement changed.');
        $this->postJson(route('api.selection.store', $request), ['offer_token' => $offer->borrower_token])->assertConflict();
    }
    $this->assertDatabaseCount('deals', 0);
})->with(['selection first' => true, 'cancellation first' => false]);

it('closes offers and pending selections when the request expires', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    $proposal = app(SelectOffer::class)->propose($borrower, $request, $offer->borrower_token);
    $request->forceFill(['bidding_deadline' => now()])->save();

    $this->artisan('funding:expire')->expectsOutput('Expired 1 funding request(s).')->assertSuccessful();
    expect($offer->fresh()->status)->toBe(OfferStatus::Expired);
    expect($proposal->fresh()->status)->toBe('superseded');
    expect($proposal->fresh()->pending_slot)->toBeNull();
});

it('closes open offers when request cancellation is approved', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    $action = app(ManageFundingRequests::class);
    $action->proposeCancellation($borrower, $request, 'Funding no longer required.');
    $action->review(bankStaff(BankRole::Approver, $borrower->membership->bank), $request, $request->approvals()->sole(), true, null);

    expect($offer->fresh()->status)->toBe(OfferStatus::Unsuccessful);
    expect($offer->fresh()->active_slot)->toBeNull();
});

it('locks selected offer terms against withdrawal editing and new bids', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $lender = bankStaff();
    $offer = marketOffer($lender, $request);
    $action = app(SelectOffer::class);
    $proposal = $action->propose($borrower, $request, $offer->borrower_token);
    $action->review(bankStaff(BankRole::Approver, $borrower->membership->bank), $proposal, true, null);
    signInUser($lender);

    $this->postJson(route('api.offers.withdraw', $offer), ['reason' => 'Attempting to withdraw reservation.'])->assertConflict();
    $this->patchJson(route('api.offers.update', $offer), offerPayload($offer->settlementInstruction, $request, ['rate' => '99.00']))->assertForbidden();
    $this->postJson(route('api.offers.store', $request), offerPayload($offer->settlementInstruction, $request))->assertForbidden();
    expect($offer->fresh()->rate)->toBe('8.1250');
});

it('keeps selected terms and instructions fixed when new versions are created', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $lender = bankStaff();
    $offer = marketOffer($lender, $request);
    $action = app(SelectOffer::class);
    $proposal = $action->propose($borrower, $request, $offer->borrower_token);
    $deal = $action->review(bankStaff(BankRole::Approver, $borrower->membership->bank), $proposal, true, null);
    SettlementInstruction::factory()->approved()->create(['bank_id' => $lender->membership->bank_id, 'details' => instructionPayload(['account_number' => 'NEW000012345678'])]);
    $offer->forceFill(['rate_units' => 999999])->save();
    signInUser($borrower);

    $this->getJson(route('api.deals.show', $deal))->assertOk()->assertJsonPath('data.terms.rate', '8.1250')->assertJsonMissingPath('data.instruction_snapshot')->assertJsonMissingPath('data.lender_bank_id');
    expect($deal->fresh()->instruction_snapshot['details']['account_number'])->toBe('DEMO000012345678');
});

it('allows only the selected lender to retain financial document access after selection', function () {
    Storage::fake('private');
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $document = financialEvidence($request);
    $winner = bankStaff();
    $loser = bankStaff();
    $offer = marketOffer($winner, $request);
    marketOffer($loser, $request);
    $action = app(SelectOffer::class);
    $proposal = $action->propose($borrower, $request, $offer->borrower_token);
    $deal = $action->review(bankStaff(BankRole::Approver, $borrower->membership->bank), $proposal, true, null);
    signInUser($winner);
    $this->get(route('funding.documents.show', $document))->assertDownload('financial-report.pdf');
    $this->getJson(route('api.deals.show', $deal))->assertOk();
    signInUser($loser);
    $this->getJson(route('funding.documents.show', $document))->assertNotFound();
    $this->getJson(route('api.deals.show', $deal))->assertNotFound();
});

it('does not disclose selected lender identity to borrower admin auditor or treasury screens', function (BankRole $role) {
    $borrower = bankStaff($role);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $lender = bankStaff();
    $lender->membership->bank->update(['name' => 'Never Reveal Lender']);
    $offer = marketOffer($lender, $request);
    $maker = $role === BankRole::Maker ? $borrower : bankStaff(BankRole::Maker, $borrower->membership->bank);
    $approver = bankStaff(BankRole::Approver, $borrower->membership->bank);
    $proposal = app(SelectOffer::class)->propose($maker, $request, $offer->borrower_token);
    $deal = app(SelectOffer::class)->review($approver, $proposal, true, null);
    signInUser($borrower);
    $this->getJson(route('api.deals.show', $deal))->assertOk()->assertJsonMissingPath('data.lenderBank')->assertJsonMissingPath('data.instructions');
    $this->get(route('deals.show', $deal))->assertOk()->assertDontSee('Never Reveal Lender')->assertDontSee('DEMO000012345678');
    $this->get(route('banks.show', $borrower->membership->bank))->assertOk()->assertDontSee('Never Reveal Lender');
})->with(BankRole::cases());

it('enforces one deal per request even when a second write bypasses the action', function () {
    $deal = Deal::factory()->create();
    $anotherOffer = Offer::factory()->create(['funding_request_id' => $deal->funding_request_id]);

    expect(fn () => DB::transaction(fn () => Deal::factory()->create(['funding_request_id' => $deal->funding_request_id, 'offer_id' => $anotherOffer->id])))->toThrow(QueryException::class);
    $this->assertDatabaseCount('deals', 1);
});

it('rejects forged selection identities and arbitrary status with 422', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    signInUser($borrower);

    $this->postJson(route('api.selection.store', $request), ['offer_token' => $offer->borrower_token, 'lender_bank_id' => $offer->bank_id, 'status' => 'unlocked'])->assertUnprocessable()->assertJsonValidationErrors(['lender_bank_id', 'status']);
    $this->assertDatabaseCount('deals', 0);
});

it('requires authentication for borrower comparison with 401', function () {
    $request = FundingRequest::factory()->published()->create();

    $this->getJson(route('api.funding.offers', $request))->assertUnauthorized();
});

it('keeps anonymous labels local to each request and changes tokens across requests', function () {
    $borrower = bankStaff();
    $lender = bankStaff();
    $first = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $second = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    marketOffer($lender, $first);
    marketOffer($lender, $second);
    signInUser($borrower);

    $firstResponse = $this->getJson(route('api.funding.offers', $first))->assertOk()->assertJsonPath('data.offers.0.label', 'Offer 1');
    $secondResponse = $this->getJson(route('api.funding.offers', $second))->assertOk()->assertJsonPath('data.offers.0.label', 'Offer 1');
    expect($firstResponse->json('data.offers.0.token'))->not->toBe($secondResponse->json('data.offers.0.token'));
});

it('seeds two lender offers repeatedly without resetting existing bank decisions', function () {
    Storage::fake('private');
    $this->seed(DemoBankSeeder::class);
    $pendingBank = Bank::query()->where('registration_number', 'DEMO-lender-two')->sole();
    $pendingBank->forceFill(['status' => BankStatus::Suspended])->save();
    $maker = User::query()->where('email', 'borrower.maker@xfunding.example')->sole();
    $maker->update(['name' => 'Preserved custom display name']);
    $this->seed(DemoMarketplaceSeeder::class);
    $this->seed(DemoMarketplaceSeeder::class);

    $this->assertDatabaseCount('offers', 4);
    $this->assertDatabaseCount('settlement_instructions', 2);
    expect($pendingBank->fresh()->status)->toBe(BankStatus::Suspended);
    expect($maker->fresh()->name)->toBe('Preserved custom display name');
    Storage::disk('private')->assertExists(FundingDocument::query()->firstOrFail()->path);
});
