<?php

use App\Actions\ChangeDealInstructions;
use App\Actions\ManageDealDisputes;
use App\Actions\ManageFeePayments;
use App\Actions\SelectOffer;
use App\BankRole;
use App\DealStatus;
use App\Models\Bank;
use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\SettlementInstruction;
use App\Models\User;
use Database\Seeders\DemoBankSeeder;
use Database\Seeders\DemoMarketplaceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

/**
 * @return array{0: User, 1: User, 2: FundingRequest, 3: User, 4: Offer}
 */
function borrowerSelectionSetup(): array
{
    $maker = bankStaff(BankRole::Maker);
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
    $lender = bankStaff(BankRole::Maker);

    return [$maker, $approver, $request, $lender, marketOffer($lender, $request)];
}

function dealWithoutReceivingInstructions(Deal $deal): Deal
{
    $deal->forceFill(['borrower_settlement_instruction_id' => null, 'borrower_instruction_snapshot' => null])->save();

    return $deal->fresh();
}

function receivingVersion(Bank $bank, string $account = 'RECEIVINGACCOUNT01'): SettlementInstruction
{
    return SettlementInstruction::factory()->approved()->create(['bank_id' => $bank->id, 'details' => instructionPayload(['account_number' => $account, 'contact_email' => 'receiving@synthetic.example'])]);
}

it('lets borrower-only treasury staff manage their own instructions', function (BankRole $role) {
    $bank = Bank::factory()->verified()->create(['can_lend' => false, 'requested_capabilities' => ['borrower']]);
    $user = bankStaff($role, $bank);
    $instruction = SettlementInstruction::factory()->create(['bank_id' => $bank->id]);
    $gate = Gate::forUser($user);

    expect($gate->allows('viewAny', SettlementInstruction::class))->toBe(in_array($role, [BankRole::Maker, BankRole::Approver], true))
        ->and($gate->allows('create', SettlementInstruction::class))->toBe($role === BankRole::Maker)
        ->and($gate->allows('review', $instruction))->toBe($role === BankRole::Approver)
        ->and($gate->allows('viewMarketplace', FundingRequest::class))->toBeFalse();
})->with(BankRole::cases());

it('requires a current approved receiving version from the borrower bank to propose a selection', function (string $case) {
    [$maker, , $request, , $offer] = borrowerSelectionSetup();
    $bank = $maker->membership->bank;
    $payload = ['offer_token' => $offer->borrower_token];
    $payload += match ($case) {
        'missing' => [],
        'another bank' => ['settlement_instruction_id' => SettlementInstruction::factory()->approved()->create()->id],
        'not approved' => ['settlement_instruction_id' => SettlementInstruction::factory()->create(['bank_id' => $bank->id])->id],
        'retired' => ['settlement_instruction_id' => SettlementInstruction::factory()->approved()->create(['bank_id' => $bank->id, 'status' => 'retired'])->id],
        'stale profile' => ['settlement_instruction_id' => SettlementInstruction::factory()->approved()->create(['bank_id' => $bank->id, 'bank_profile' => ['name' => 'Old', 'country_code' => 'LK', 'registration_number' => 'OLD']])->id],
    };
    signInUser($maker);

    $this->postJson(route('api.selection.store', $request), $payload)->assertUnprocessable()->assertJsonValidationErrors('settlement_instruction_id');
    $this->assertDatabaseCount('selection_proposals', 0);
})->with(['missing', 'another bank', 'not approved', 'retired', 'stale profile']);

it('offers makers their approved versions without banking details and tells them when none exist', function () {
    [$maker, $approver, $request] = borrowerSelectionSetup();
    signInUser($maker);
    $this->getJson(route('api.funding.offers', $request))->assertOk()->assertJsonPath('data.receivingInstructions', []);
    $this->get(route('funding.offers', $request))->assertOk()->assertSee('Approve your receiving account first')->assertDontSee('Propose Offer 1');

    $version = receivingVersion($maker->membership->bank);
    SettlementInstruction::factory()->create(['bank_id' => $maker->membership->bank_id]);
    $response = $this->getJson(route('api.funding.offers', $request))->assertOk()->assertJsonCount(1, 'data.receivingInstructions')->assertJsonPath('data.receivingInstructions.0.id', $version->id);
    expect($response->getContent())->not->toContain('RECEIVINGACCOUNT01');
    $this->get(route('funding.offers', $request))->assertOk()->assertSee('Your receiving account')->assertSee('Propose Offer 1');

    signInUser($approver);
    $this->getJson(route('api.funding.offers', $request))->assertOk()->assertJsonPath('data.receivingInstructions', []);
});

it('snapshots the proposed receiving version encrypted on the deal and keeps it fixed', function () {
    [$maker, $approver, $request, , $offer] = borrowerSelectionSetup();
    $version = receivingVersion($maker->membership->bank);
    $proposal = app(SelectOffer::class)->propose($maker, $request, $offer->borrower_token, $version->id);
    signInUser($approver);
    $this->get(route('funding.offers', $request))->assertOk()->assertSee('Receiving instructions: version '.$version->version);

    $deal = app(SelectOffer::class)->review($approver, $proposal, true, null);
    receivingVersion($maker->membership->bank, 'NEWERACCOUNT0002');

    expect($deal->fresh()->borrower_settlement_instruction_id)->toBe($version->id)
        ->and($deal->fresh()->borrower_instruction_snapshot['details']['account_number'])->toBe('RECEIVINGACCOUNT01')
        ->and(DB::table('deals')->where('id', $deal->id)->value('borrower_instruction_snapshot'))->not->toContain('RECEIVINGACCOUNT01');
});

it('refuses approval when the proposed receiving version is no longer current', function () {
    [$maker, $approver, $request, , $offer] = borrowerSelectionSetup();
    $version = receivingVersion($maker->membership->bank);
    $proposal = app(SelectOffer::class)->propose($maker, $request, $offer->borrower_token, $version->id);
    $maker->membership->bank->update(['name' => 'Renamed Borrower Bank']);
    signInUser($approver->fresh(['membership.bank']));

    $this->postJson(route('api.selection.review', ['fundingRequest' => $request, 'selection' => $proposal]), ['decision' => 'approve'])->assertConflict();
    $this->assertDatabaseCount('deals', 0);
});

it('discloses the borrower receiving account to the selected lender only after the fee is verified', function () {
    [$maker, $approver, $request, $lender, $offer] = borrowerSelectionSetup();
    $version = receivingVersion($maker->membership->bank);
    $deal = app(SelectOffer::class)->review($approver, app(SelectOffer::class)->propose($maker, $request, $offer->borrower_token, $version->id), true, null);
    $lenderApprover = bankStaff(BankRole::Approver, $lender->membership->bank);
    signInUser($lender);
    expect($this->getJson(route('api.deals.show', $deal))->assertOk()->getContent())->not->toContain('RECEIVINGACCOUNT01')->not->toContain($version->id);
    $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();

    $payment = app(ManageFeePayments::class)->initiate($approver->fresh(['membership.bank']), $deal->fresh());
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk()->assertJsonPath('result', 'verified');

    foreach ([$lender, $lenderApprover] as $user) {
        signInUser($user);
        $this->getJson(route('api.deals.settlement', $deal))->assertOk()
            ->assertJsonPath('data.borrower.name', $maker->membership->bank->name)
            ->assertJsonPath('data.borrowerInstructions.accountNumber', 'RECEIVINGACCOUNT01')
            ->assertJsonPath('data.borrowerInstructions.contactEmail', 'receiving@synthetic.example');
        $this->get(route('deals.settlement', $deal))->assertOk()->assertSee('Borrower receiving account')->assertSee('RECEIVINGACCOUNT01');
        expect($this->getJson(route('api.deals.show', $deal))->assertOk()->getContent())->not->toContain('RECEIVINGACCOUNT01');
    }
    foreach ([BankRole::Admin, BankRole::Auditor] as $role) {
        signInUser(bankStaff($role, $lender->membership->bank));
        $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
    }
    signInUser(bankStaff(BankRole::Maker));
    $this->getJson(route('api.deals.settlement', $deal))->assertNotFound();
    signInUser(User::factory()->platformAdmin()->create());
    expect($this->getJson(route('api.deals.settlement', $deal))->status())->toBeIn([403, 404]);
});

it('blocks payment until an older deal records receiving instructions through independent approval', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = dealWithoutReceivingInstructions(paymentDeal($approver));
    $maker = bankStaff(BankRole::Maker, $approver->membership->bank);
    $version = receivingVersion($approver->membership->bank);
    signInUser($approver);
    $this->getJson(route('api.deals.show', $deal))->assertOk()->assertJsonPath('data.can.initiatePayment', false)->assertJsonPath('data.receivingInstructionsRecorded', false);
    $this->get(route('deals.show', $deal))->assertOk()->assertSee('Receiving instructions required');
    $this->postJson(route('api.payments.store', $deal))->assertConflict();

    signInUser($maker);
    $this->get(route('deals.show', $deal))->assertOk()->assertSee('Update approved settlement instructions');
    $changeId = $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $version->id])->assertCreated()->json('data.id');
    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $changeId]), ['decision' => 'approve'])->assertForbidden();

    signInUser($approver);
    $this->getJson(route('api.deals.show', $deal))->assertOk()->assertJsonPath('data.instructionChange.id', $changeId)->assertJsonPath('data.instructionChange.canReview', true);
    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $changeId]), ['decision' => 'approve'])->assertOk()
        ->assertJsonPath('data.can.initiatePayment', true)->assertJsonPath('data.receivingInstructionsRecorded', true);
    $this->postJson(route('api.payments.store', $deal))->assertCreated();
});

it('keeps lender disclosure on a paid older deal and lets the borrower add receiving instructions', function () {
    $deal = dealWithoutReceivingInstructions(unlockedDeal());
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $version = receivingVersion($deal->borrowerBank);
    signInUser($maker);
    $this->getJson(route('api.deals.settlement', $deal))->assertOk()->assertJsonPath('data.borrowerInstructions', null)->assertJsonPath('data.instructions.accountNumber', 'DEMO000012345678');
    $this->get(route('deals.settlement', $deal))->assertOk()->assertSee('has not recorded approved receiving instructions');
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertConflict();

    $change = app(ChangeDealInstructions::class)->propose($maker, $deal, $version->id);
    app(ChangeDealInstructions::class)->review(bankStaff(BankRole::Approver, $deal->borrowerBank), $change, true, null);

    expect($change->fresh()->previous_snapshot)->toBeNull();
    $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertCreated();
});

it('requires an open dispute to change recorded borrower instructions after unlock', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $version = receivingVersion($deal->borrowerBank, 'CORRECTEDACCOUNT01');
    signInUser($maker);
    $this->postJson(route('api.deal-instructions.store', $deal), ['settlement_instruction_id' => $version->id])->assertConflict();

    $original = $deal->fresh()->borrower_instruction_snapshot;
    $confirmation = confirmedInstitution($deal->fresh(), $deal->lenderBank);
    app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal->fresh(), 'The receiving account needs a documented correction.');
    $change = app(ChangeDealInstructions::class)->propose($maker, $deal->fresh(), $version->id);
    app(ChangeDealInstructions::class)->review(bankStaff(BankRole::Approver, $deal->borrowerBank), $change, true, null);

    expect($confirmation->fresh()->status)->toBe('superseded')
        ->and($change->fresh()->previous_snapshot)->toBe($original)
        ->and($deal->fresh()->borrower_instruction_snapshot['details']['account_number'])->toBe('CORRECTEDACCOUNT01')
        ->and($deal->fresh()->instruction_snapshot['details']['account_number'])->toBe('DEMO000012345678')
        ->and($deal->fresh()->status)->toBe(DealStatus::Disputed);
});

it('keeps each institution out of the other side pending instruction change', function () {
    $borrower = bankStaff(BankRole::Maker);
    $deal = paymentDeal($borrower);
    $version = receivingVersion($borrower->membership->bank);
    $change = app(ChangeDealInstructions::class)->propose($borrower, $deal, $version->id);
    expect($change->side)->toBe('borrower');

    signInUser(bankStaff(BankRole::Approver, $deal->lenderBank));
    $response = $this->getJson(route('api.deals.show', $deal))->assertOk()->assertJsonPath('data.instructionReviewPending', true)->assertJsonMissingPath('data.instructionChange');
    expect($response->getContent())->not->toContain($version->id);
    $this->postJson(route('api.deal-instructions.review', ['deal' => $deal, 'change' => $change]), ['decision' => 'approve'])->assertForbidden();
    expect($deal->fresh()->borrower_settlement_instruction_id)->not->toBe($version->id);
});

it('blocks payment and settlement when the borrower bank profile no longer matches its recorded version', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = paymentDeal($approver);
    $approver->membership->bank->update(['name' => 'Renamed Borrower Bank']);
    signInUser($approver->fresh(['membership.bank']));

    $this->postJson(route('api.payments.store', $deal))->assertConflict();
    expect($deal->fresh()->hasApprovedBorrowerInstructions())->toBeFalse();
});

it('adds the demo borrower receiving version once without resetting bank decisions', function () {
    $this->seed(DemoBankSeeder::class);
    $this->seed(DemoMarketplaceSeeder::class);
    $this->seed(DemoMarketplaceSeeder::class);
    $bank = Bank::query()->where('registration_number', 'DEMO-borrower')->sole();

    expect(SettlementInstruction::query()->where('bank_id', $bank->id)->where('status', 'approved')->count())->toBe(1);
});
