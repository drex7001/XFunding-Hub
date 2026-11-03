<?php

use App\Actions\DescribeWorkflowProgress;
use App\Actions\ManageFeePayments;
use App\BankRole;
use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\Bank;
use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\FundingRequestApproval;
use App\Models\Offer;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\Notifications\DealActivityNotification;
use App\OfferStatus;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    enableDemoPayments();
    Storage::fake('private');
});

/** @return array<int, string> */
function reachedSteps(array $steps): array
{
    return array_map(fn (array $step): string => $step['state'], $steps);
}

it('opens a role-aware home page instead of redirecting to the bank profile', function (BankRole $role) {
    signInUser(bankStaff($role));

    $this->get(route('dashboard'))->assertOk()->assertSee('Needs your action')->assertSee('Welcome back');
})->with(BankRole::cases());

it('opens the home page for platform operations and shows the waiting count in the navigation', function () {
    signInUser(User::factory()->platformAdmin()->create());
    Bank::factory()->create(['name' => 'Waiting For Verification Bank']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Waiting For Verification Bank')
        ->assertSee('Verify institution')
        ->assertSee('nav-count', false);
});

it('returns a stale form submission to its page with the reason', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = unlockedDealFor($approver);
    signInUser($approver);
    $page = route('deals.show', $deal);

    $this->from($page)
        ->post(route('payments.store', $deal))
        ->assertRedirect($page)
        ->assertSessionHasErrors(['conflict' => 'This deal is not awaiting a payable fee quote.']);
    $this->from($page)
        ->followingRedirects()
        ->post(route('payments.store', $deal))
        ->assertOk()
        ->assertSee('This deal is not awaiting a payable fee quote.');
});

it('keeps conflict responses for API clients', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = unlockedDealFor($approver);
    signInUser($approver);

    $this->postJson(route('api.payments.store', $deal))->assertConflict();
});

it('renders friendly error pages without internal details', function () {
    signInUser(bankStaff(BankRole::Maker));

    $this->get('/banks/999999')->assertNotFound()->assertSee('This page is not available')->assertDontSee('Exception');
    $this->get(route('operations'))->assertForbidden()->assertSee('You cannot do this')->assertDontSee('Exception');
});

it('groups money for display without changing the exact value', function (string $amount, string $grouped) {
    expect(FundingRequest::groupDecimal($amount))->toBe($grouped);
})->with([
    ['0.01', '0.01'], ['100', '100'], ['999.99', '999.99'], ['1000', '1,000'], ['1000.50', '1,000.50'],
    ['250000000.00', '250,000,000.00'], ['999999999999.99', '999,999,999,999.99'],
]);

it('shows grouped amounts in the interface and leaves the API exact', function () {
    $maker = bankStaff(BankRole::Maker);
    $request = fundingDraft($maker, ['amount_minor' => 25000000000]);
    signInUser($maker);

    $this->get(route('funding.index'))->assertSee('250,000,000.00');
    $this->get(route('funding.show', $request))->assertSee('250,000,000.00');
    $this->getJson(route('api.funding.show', $request))->assertJsonPath('data.amount', '250000000.00');
});

it('describes funding progress for each status', function (FundingRequestStatus $status, array $expected) {
    $request = FundingRequest::factory()
        ->create([
            'status' => $status,
            'published_at' => $status === FundingRequestStatus::Draft
                || $status === FundingRequestStatus::PendingApproval
                ? null
                : now(),
            'bidding_deadline' => now()->addDay(),
        ]);

    expect(reachedSteps(app(DescribeWorkflowProgress::class)->funding($request)))->toBe($expected);
})->with([
    'draft' => [FundingRequestStatus::Draft, ['current', 'upcoming', 'upcoming', 'upcoming', 'upcoming', 'upcoming']],
    'approval' => [FundingRequestStatus::PendingApproval, [
        'done',
        'current',
        'upcoming',
        'upcoming',
        'upcoming',
        'upcoming',
    ]],
    'open' => [FundingRequestStatus::Open, ['done', 'done', 'current', 'upcoming', 'upcoming', 'upcoming']],
    'cancelled' => [FundingRequestStatus::Cancelled, ['done', 'done', 'stopped', 'upcoming', 'upcoming', 'upcoming']],
]);

it('describes deal progress for each status', function (DealStatus $status, array $expected) {
    $deal = Deal::factory()->quoted()->create();
    $deal->forceFill(['status' => $status])->save();

    expect(reachedSteps(app(DescribeWorkflowProgress::class)->deal($deal->fresh())))->toBe($expected);
})->with([
    'awaiting fee' => [DealStatus::AwaitingFee, ['done', 'current', 'upcoming', 'upcoming']],
    'unlocked' => [DealStatus::Unlocked, ['done', 'done', 'current', 'upcoming']],
    'disputed' => [DealStatus::Disputed, ['done', 'done', 'current', 'upcoming']],
    'settled' => [DealStatus::Settled, ['done', 'done', 'done', 'done']],
    'cancelled' => [DealStatus::Cancelled, ['done', 'stopped', 'upcoming', 'upcoming']],
    'closed unsettled' => [DealStatus::ClosedUnsettled, ['done', 'done', 'stopped', 'upcoming']],
]);

it('puts the right next step on a funding request for each person', function () {
    $maker = bankStaff(BankRole::Maker);
    $bank = $maker->membership->bank;
    $request = fundingDraft($maker);
    signInUser($maker);
    $this->get(route('funding.show', $request))
        ->assertSee('Attach financial documents')
        ->assertDontSee('Submit for publication approval');

    financialEvidence($request);
    $this->get(route('funding.show', $request))->assertSee('Submit for publication approval');
    $this->post(route('funding.submit', $request))->assertRedirect();
    $this->get(route('funding.show', $request))
        ->assertSee('Publication approval pending')
        ->assertDontSee('Approve publication');

    signInUser(bankStaff(BankRole::Approver, $bank));
    $this->get(route('funding.show', $request))
        ->assertSee('Approve publication')
        ->assertSee('Request changes / reject');

    expect(
        FundingRequestApproval::query()->where('funding_request_id', $request->id)->where('status', 'pending')->count()
    )->toBe(1);
});

it('tells a lender whether to prepare an offer or return to its own', function () {
    $request = FundingRequest::factory()->published()->create();
    $maker = bankStaff(BankRole::Maker);
    signInUser($maker);
    $this->get(route('funding.show', $request))->assertSee('Prepare an offer')->assertDontSee('Your bank has an offer');

    $offer = marketOffer($maker, $request);
    $this->get(route('funding.show', $request))
        ->assertSee('Your bank has an offer on this request')
        ->assertSee(route('offers.show', $offer))
        ->assertDontSee(route('offers.create', $request));

    signInUser(bankStaff(BankRole::Approver));
    $this->get(route('funding.show', $request))->assertSee('A treasury maker at your bank prepares offers');
});

it('can create an offer and send it for approval in one step', function () {
    $maker = bankStaff(BankRole::Maker);
    $request = FundingRequest::factory()->published()->create();
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->post(route('offers.store', $request), offerPayload($instruction, $request, ['submit_for_approval' => '1']))
        ->assertRedirect()
        ->assertSessionHas('status', 'Offer sent for independent lender approval.');
    $offer = Offer::query()->sole();
    expect($offer->status)->toBe(OfferStatus::PendingApproval)->and($offer->pendingApproval)->not->toBeNull();

    $this->patch(
        route('offers.update', $offer),
        offerPayload($instruction, $request, ['rate' => '8.5000', 'submit_for_approval' => '1']),
    )->assertRedirect();
    expect($offer->fresh()->status)->toBe(OfferStatus::PendingApproval)->and($offer->fresh()->rate)->toBe('8.5000');
});

it('still saves an offer as a draft when asked', function () {
    $maker = bankStaff(BankRole::Maker);
    $request = FundingRequest::factory()->published()->create();
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);

    $this->post(route('offers.store', $request), offerPayload($instruction, $request))->assertRedirect();

    expect(Offer::query()->sole()->status)->toBe(OfferStatus::Draft);
});

it('asks the borrower for one receiving account across the whole comparison and marks the lowest rate', function () {
    $maker = bankStaff(BankRole::Maker);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
    receivingInstructionId($request);
    marketOffer(bankStaff(), $request, ['rate_units' => 90000]);
    marketOffer(bankStaff(), $request, ['rate_units' => 80000]);
    signInUser($maker);

    $response = $this->get(route('funding.offers', $request))
        ->assertOk()
        ->assertSee('Lowest rate')
        ->assertSee('Propose Offer 1')
        ->assertSee('Propose Offer 2');

    expect(substr_count($response->getContent(), 'name="settlement_instruction_id"'))->toBe(1)
        ->and(substr_count($response->getContent(), 'name="offer_token"'))->toBe(2);
});

it('leads each person on a deal to their own next step', function () {
    $borrowerApprover = bankStaff(BankRole::Approver);
    $deal = paymentDeal($borrowerApprover);
    $lenderMaker = bankStaff(BankRole::Maker, $deal->lenderBank);
    $borrowerMaker = bankStaff(BankRole::Maker, $deal->borrowerBank);

    signInUser($borrowerApprover);
    $this->get(route('deals.show', $deal))->assertSee('Pay the platform fee')->assertSee('Request demo fee payment');
    signInUser($borrowerMaker);
    $this->get(route('deals.show', $deal))
        ->assertSee('A treasury approver pays the platform fee')
        ->assertDontSee('Request demo fee payment');
    signInUser($lenderMaker);
    $this->get(route('deals.show', $deal))->assertSee('Waiting for the borrower to pay the platform fee');

    $payment = app(ManageFeePayments::class)->initiate($borrowerApprover->fresh(['membership.bank']), $deal);
    signInUser($borrowerApprover);
    $this->get(route('deals.show', $deal))
        ->assertSee('Payment requested')
        ->assertSee($payment->provider_reference)
        ->assertSee('payments:simulate');
    signInUser($lenderMaker);
    $this->get(route('deals.show', $deal))
        ->assertDontSee($payment->provider_reference)
        ->assertDontSee('payments:simulate');

    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    $this->get(route('deals.show', $deal))
        ->assertSee('Send the principal, then confirm it')
        ->assertSee('Propose settlement confirmation');
    signInUser($borrowerMaker);
    $this->get(route('deals.show', $deal))->assertSee('Confirm the principal was received');
    $this->post(route('confirmations.store', $deal), settlementPayload())->assertRedirect();
    $this->get(route('deals.show', $deal))->assertSee('Your confirmation is awaiting review');
    signInUser($borrowerApprover);
    $this->get(route('deals.show', $deal))->assertSee('Approve settlement confirmation');
});

it('marks all of a person\'s notifications read without touching anyone else', function () {
    $deal = unlockedDeal();
    $reader = bankStaff(BankRole::Approver, $deal->borrowerBank);
    $colleague = bankStaff(BankRole::Approver, $deal->borrowerBank);
    foreach ([$reader, $colleague] as $user) {
        $user->notify(
            new DealActivityNotification($deal->id, $deal->borrower_bank_id, 'settled', null, [
                BankRole::Approver->value,
            ])
        );
        $user->notify(
            new DealActivityNotification($deal->id, $deal->borrower_bank_id, 'dispute.opened', null, [
                BankRole::Approver->value,
            ])
        );
    }
    signInUser($reader);
    $this->get(route('notifications.index'))->assertSee('Mark all as read');

    $this->post(route('notifications.read-all'))->assertRedirect();

    expect($reader->unreadNotifications()->count())->toBe(0)->and($colleague->unreadNotifications()->count())->toBe(2);
    $this->get(route('notifications.index'))->assertDontSee('Mark all as read');
    $this->postJson(route('api.notifications.read-all'))->assertOk()->assertJsonPath('data.read', 0);
});

it('uses readable pagination', function () {
    $maker = bankStaff(BankRole::Maker);
    FundingRequest::factory()
        ->count(16)
        ->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    signInUser($maker);

    $this->get(route('funding.index'))->assertOk()->assertSee('Showing 1–15 of 16')->assertSee('Next');
});

function unlockedDealFor(User $approver): Deal
{
    $deal = paymentDeal($approver);
    $payment = app(ManageFeePayments::class)->initiate($approver, $deal);
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();

    return $deal->fresh();
}

test('a rendered time can be followed directly by punctuation', function (string $attributes, string $expected) {
    expect(Blade::render("Due <x-when at=\"2026-11-03 17:52:00\" {$attributes} />."))->toContain($expected);
})->with([
    'date and time' => ['', '03 Nov 2026, 17:52 UTC</time>.'],
    'date only' => ['date-only', '03 Nov 2026</time>.'],
]);

test('the compact stepper summary names the step in focus', function (array $states, string $expected) {
    $steps = array_map(
        fn (string $state, int $index): array => ['label' => 'Stage '.($index + 1), 'state' => $state],
        $states,
        array_keys($states),
    );

    expect(Blade::render('<x-stepper :steps="$steps" />', ['steps' => $steps]))->toContain($expected);
})->with([
    'in progress' => [['done', 'current', 'upcoming'], 'Step 2 of 3: Stage 2'],
    'stopped' => [['done', 'stopped', 'upcoming'], 'Step 2 of 3: Stage 2'],
    'all complete' => [['done', 'done', 'done'], 'Step 3 of 3: Stage 3'],
]);
