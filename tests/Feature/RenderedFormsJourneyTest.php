<?php

use App\BankRole;
use App\DealStatus;
use App\Models\AccessRequest;
use App\Models\Bank;
use App\Models\BankInvitation;
use App\Models\Deal;
use App\Models\DealDispute;
use App\Models\FeePayment;
use App\Models\FundingRequest;
use App\Models\PaymentReceipt;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\Notifications\BankInvitationNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    enableDemoPayments();
    Storage::fake('private');
});

function renderedDom(TestResponse $page): DOMXPath
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML((string) $page->getContent());
    libxml_clear_errors();

    return new DOMXPath($document);
}

/**
 * Submits a rendered form the way a browser would: only fields and buttons present in the HTML
 * are sent, required fields must be filled, and any field a test fills must exist on the page.
 *
 * @param  array<string, mixed>  $values
 * @param  array<string, UploadedFile>  $files
 */
function submitRendered(TestResponse $page, string $action, string $button, array $values = [], array $files = []): TestResponse
{
    $xpath = renderedDom($page);
    $forms = $xpath->query('//form[@action="'.$action.'"]');
    expect($forms->length)->toBeGreaterThan(0, "No form posts to {$action}");
    $form = null;
    foreach ($forms as $candidate) {
        if ($xpath->query('.//button[contains(normalize-space(.), "'.$button.'")]', $candidate)->length > 0) {
            $form = $candidate;
        }
    }
    expect($form)->not->toBeNull("No \"{$button}\" button in the form posting to {$action}");

    $payload = [];
    $known = [];
    foreach ($xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $form) as $field) {
        $name = str_replace('[]', '', $field->getAttribute('name'));
        $known[$name] = true;
        $type = $field->nodeName === 'input' ? $field->getAttribute('type') : $field->nodeName;
        if ($type === 'checkbox') {
            if (! array_key_exists($name, $values) && $field->hasAttribute('checked')) {
                $payload[$name][] = $field->getAttribute('value');
            }

            continue;
        }
        if ($type === 'file') {
            expect(! $field->hasAttribute('required') || isset($files[$name]))->toBeTrue("Required file {$name} was not attached");

            continue;
        }
        $value = match ($type) {
            'textarea' => $field->textContent,
            'select' => (function () use ($xpath, $field): string {
                $selected = $xpath->query('.//option[@selected]', $field)->item(0) ?? $xpath->query('.//option', $field)->item(0);

                return $selected?->getAttribute('value') ?? '';
            })(),
            default => $field->getAttribute('value'),
        };
        $payload[$name] = $values[$name] ?? $value;
        expect($payload[$name] !== '' || ! $field->hasAttribute('required'))->toBeTrue("Required field {$name} was left empty");
    }
    foreach ($values as $name => $value) {
        expect(isset($known[$name]))->toBeTrue("The form posting to {$action} has no {$name} field");
        $payload[$name] = $value;
    }
    $pressed = $xpath->query('.//button[contains(normalize-space(.), "'.$button.'")]', $form)->item(0);
    if ($pressed->hasAttribute('name')) {
        $payload[$pressed->getAttribute('name')] = $pressed->getAttribute('value');
    }
    $method = strtoupper($form->getAttribute('method') ?: 'GET');
    $response = test()->followingRedirects()->call($method, $action, $payload, [], $files);

    return tap($response, fn (TestResponse $result) => $result->assertOk()->assertDontSee('alert--danger', false));
}

function openFromHome(string $task): TestResponse
{
    $home = test()->get(route('dashboard'))->assertOk();
    $links = renderedDom($home)->query('//a[contains(@class, "work-item")][.//*[contains(concat(" ", @class, " "), " work-item__task ")][normalize-space(.)="'.$task.'"]]');
    expect($links->length)->toBeGreaterThan(0, "Home has no \"{$task}\" item");

    return test()->get($links->item(0)->getAttribute('href'))->assertOk();
}

/** @return array{0: User, 1: User} */
function treasuryPair(Bank $bank): array
{
    return [bankStaff(BankRole::Maker, $bank), bankStaff(BankRole::Approver, $bank)];
}

function proposeAndApproveInstructions(User $maker, User $approver, string $account): void
{
    signInUser($maker);
    expect(renderedDom(test()->get(route('dashboard')))->query('//section[contains(@class, "next-step")]//h2[normalize-space(.)="Add settlement instructions"]')->length)->toBe(1);
    submitRendered(test()->get(route('instructions.index')), route('instructions.store'), 'Propose instruction version', [
        'channel' => 'swift', 'bic' => 'DEMOLKXX', 'account_name' => 'Synthetic Treasury Account', 'account_number' => $account,
        'contact_name' => 'Synthetic Treasury Contact', 'contact_email' => 'desk@synthetic.example',
    ])->assertSee('Independent approval is pending');

    signInUser($approver);
    $instruction = SettlementInstruction::query()->where('bank_id', $approver->membership->bank_id)->sole();
    submitRendered(openFromHome('Approve settlement instructions'), route('instructions.review', $instruction), 'Approve instructions')->assertSee('Approved');
}

it('takes a request from draft to settled through the rendered screens, guided by the home page', function () {
    [$borrowerMaker, $borrowerApprover] = treasuryPair(Bank::factory()->verified()->create(['can_lend' => false, 'requested_capabilities' => ['borrower']]));
    [$lenderOneMaker, $lenderOneApprover] = treasuryPair(Bank::factory()->verified()->create(['can_borrow' => false, 'requested_capabilities' => ['lender'], 'name' => 'Hidden Lender One']));
    [$lenderTwoMaker, $lenderTwoApprover] = treasuryPair(Bank::factory()->verified()->create(['can_borrow' => false, 'requested_capabilities' => ['lender'], 'name' => 'Hidden Lender Two']));

    signInUser($borrowerMaker);
    submitRendered($this->get(route('funding.create')), route('funding.store'), 'Save draft and continue', [
        'title' => 'Short-term treasury liquidity', 'amount' => '250000000.00', 'tenor_days' => '7',
        'settlement_date' => now()->addDays(4)->toDateString(), 'bidding_deadline' => now()->addDays(2)->format('Y-m-d\TH:i'),
        'borrower_background' => 'Synthetic borrower financial history supplied for this funding request.',
    ])->assertSee('Attach financial documents');
    $request = FundingRequest::query()->sole();
    $show = route('funding.show', $request);

    $pdf = UploadedFile::fake()->createWithContent('synthetic-audit.pdf', "%PDF-1.4\nSynthetic bank audit evidence\n%%EOF\n");
    submitRendered($this->get($show), route('funding.documents.store', $request), 'Upload privately', [], ['document' => $pdf])->assertSee('Submit for publication approval');
    submitRendered($this->get($show), route('funding.submit', $request), 'Submit for publication approval')->assertSee('Publication approval pending');

    signInUser($borrowerApprover);
    $approval = $request->approvals()->sole();
    submitRendered(openFromHome('Approve publication'), route('funding.review', ['fundingRequest' => $request, 'approval' => $approval]), 'Approve publication')->assertSee('Waiting for lender offers');

    foreach ([[$lenderOneMaker, $lenderOneApprover, 'LENDERONEACCOUNT01', '8.1250'], [$lenderTwoMaker, $lenderTwoApprover, 'LENDERTWOACCOUNT02', '7.9000']] as [$maker, $approver, $account, $rate]) {
        proposeAndApproveInstructions($maker, $approver, $account);
        signInUser($maker);
        $this->get(route('marketplace'))->assertOk()->assertSee('Short-term treasury liquidity')->assertSee('250,000,000.00');
        $this->get($show)->assertOk()->assertSee('Prepare an offer');
        submitRendered($this->get(route('offers.create', $request)), route('offers.store', $request), 'Save and send for approval', ['rate' => $rate])->assertSee('Independent approval is pending');
        signInUser($approver);
        $offer = $request->offers()->where('bank_id', $approver->membership->bank_id)->sole();
        submitRendered(openFromHome('Approve offer'), route('offers.review', ['offer' => $offer, 'approval' => $offer->pendingApproval]), 'Approve offer')->assertSee('Your offer is live');
    }

    proposeAndApproveInstructions($borrowerMaker, $borrowerApprover, 'BORROWERRECEIVING1');
    signInUser($borrowerMaker);
    $comparison = openFromHome('Compare offers')->assertDontSee('Hidden Lender')->assertSee('Lowest rate');
    submitRendered($comparison, route('selection.store', $request), 'Propose Offer 2')->assertSee('Selection approval pending');

    signInUser($borrowerApprover);
    $selection = $request->pendingSelection()->sole();
    submitRendered(openFromHome('Approve offer selection')->assertDontSee('Hidden Lender'), route('selection.review', ['fundingRequest' => $request, 'selection' => $selection]), 'Approve selection')
        ->assertSee('Pay the platform fee')->assertDontSee('Hidden Lender');
    $deal = Deal::query()->sole();
    expect($deal->lender_bank_id)->toBe($lenderTwoMaker->membership->bank_id);

    submitRendered(openFromHome('Pay platform fee'), route('payments.store', $deal), 'Request demo fee payment')->assertSee('Payment requested')->assertDontSee('LENDERTWOACCOUNT02');
    signedPaymentNotification(paymentNotificationPayload(FeePayment::query()->sole()))->assertOk()->assertJsonPath('result', 'verified');

    signInUser($lenderTwoMaker);
    $this->get(route('deals.settlement', $deal))->assertOk()->assertSee('BORROWERRECEIVING1');
    submitRendered(openFromHome('Send principal and confirm'), route('confirmations.store', $deal), 'Propose settlement confirmation', ['reference' => 'SYNTHETIC-REF-1'])->assertSee('Your confirmation is awaiting review');
    signInUser($lenderTwoApprover);
    $confirmation = $deal->confirmations()->sole();
    submitRendered(openFromHome('Review settlement confirmation'), route('confirmations.review', ['deal' => $deal, 'confirmation' => $confirmation]), 'Approve settlement confirmation')->assertSee('Waiting for the borrower to confirm');

    signInUser($borrowerMaker);
    $this->get(route('deals.settlement', $deal))->assertOk()->assertSee('LENDERTWOACCOUNT02')->assertDontSee('LENDERONEACCOUNT01')->assertDontSee('Hidden Lender One');
    submitRendered(openFromHome('Confirm principal received'), route('confirmations.store', $deal), 'Propose settlement confirmation')->assertSee('Your confirmation is awaiting review');
    signInUser($borrowerApprover);
    $own = $deal->confirmations()->where('bank_id', $deal->borrower_bank_id)->sole();
    submitRendered(openFromHome('Review settlement confirmation'), route('confirmations.review', ['deal' => $deal, 'confirmation' => $own]), 'Approve settlement confirmation')->assertSee('Settled');

    expect($deal->fresh()->status)->toBe(DealStatus::Settled);
    foreach ([$borrowerMaker, $borrowerApprover, $lenderTwoMaker, $lenderTwoApprover] as $user) {
        signInUser($user);
        $this->get(route('dashboard'))->assertOk()->assertSee('You\'re all caught up');
    }
});

it('runs onboarding, team changes and platform accounts through the rendered screens', function () {
    Notification::fake();
    $operator = User::factory()->platformAdmin()->create();
    signInUser($operator);
    submitRendered($this->get(route('operations')), route('banks.store'), 'Create bank and invite admin', [
        'name' => 'Synthetic Onboarding Bank', 'country_code' => 'LK', 'registration_number' => 'SYN-1', 'requested_capabilities' => ['borrower', 'lender'],
        'admin_name' => 'First Admin', 'admin_email' => 'first.admin@example.test',
    ])->assertSee('Verify this institution');
    $bank = Bank::query()->where('registration_number', 'SYN-1')->sole();
    Notification::assertSentOnDemand(BankInvitationNotification::class);

    $admin = bankStaff(BankRole::Admin, $bank);
    $otherAdmin = bankStaff(BankRole::Admin, $bank);
    signInUser($admin);
    openFromHomeSetup('Complete onboarding');
    $pdf = UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\nSynthetic licence\n%%EOF\n");
    submitRendered($this->get(route('banks.show', $bank)), route('documents.store', $bank), 'Upload privately', [], ['document' => $pdf])->assertSee('licence.pdf');

    signInUser($operator);
    submitRendered(openFromHome('Verify institution'), route('banks.verify', $bank), 'Verify institution', ['capabilities' => ['borrower', 'lender'], 'reason' => 'Synthetic documents reviewed.'])->assertSee('This institution is verified');
    expect($bank->fresh()->can_lend)->toBeTrue();

    signInUser($admin->fresh(['membership.bank']));
    $team = $this->get(route('banks.show', $bank));
    submitRendered($team, route('access.store'), 'Submit invitation for approval', ['name' => 'New Maker', 'email' => 'new.maker@example.test', 'roles' => [BankRole::Maker->value]]);
    $invitation = AccessRequest::query()->where('kind', 'invitation')->sole();
    signInUser($otherAdmin->fresh(['membership.bank']));
    submitRendered(openFromHome('Approve access request'), route('access.review', $invitation), 'Approve');
    expect(BankInvitation::query()->where('email', 'new.maker@example.test')->exists())->toBeTrue();

    $colleague = bankStaff(BankRole::Maker, $bank);
    signInUser($admin->fresh(['membership.bank']));
    submitRendered($this->get(route('banks.show', $bank)), route('access.revoke', $colleague->membership), 'Revoke membership');
    expect($colleague->membership->fresh()->active)->toBeFalse();
    submitRendered($this->get(route('banks.show', $bank)), route('access.reinstate', $colleague->membership), 'Request reinstatement', ['roles' => [BankRole::Maker->value]]);
    $reinstatement = AccessRequest::query()->where('kind', 'reinstatement')->sole();
    signInUser($otherAdmin->fresh(['membership.bank']));
    submitRendered($this->get(route('banks.show', $bank)), route('access.review', $reinstatement), 'Approve');
    expect($colleague->membership->fresh()->active)->toBeTrue();

    signInUser($operator);
    $this->get(route('banks.audit', $bank))->assertOk()->assertSee('Bank verified');
    submitRendered($this->get(route('platform-accounts.index')), route('platform-accounts.store'), 'Create account', ['name' => 'Second Operator', 'email' => 'second@example.test', 'reason' => 'Additional operations coverage.'])->assertSee('Second Operator');
    $second = User::query()->where('email', 'second@example.test')->sole();
    submitRendered($this->get(route('platform-accounts.index')), route('platform-accounts.deactivate', $second), 'Deactivate', ['reason' => 'Operator left the team.'])->assertSee('Reactivate');
    submitRendered($this->get(route('banks.show', $bank)), route('banks.suspend', $bank), 'Suspend institution', ['reason' => 'Synthetic suspension for review.'])->assertSee('This institution is suspended');
    submitRendered($this->get(route('banks.show', $bank)), route('banks.reopen', $bank), 'Reopen for review', ['reason' => 'Synthetic reopening after review.'])->assertSee('Verify this institution');
});

it('runs disputes, exceptions and corrections through the rendered screens', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $approver = bankStaff(BankRole::Approver, $deal->borrowerBank);
    $operator = User::factory()->platformAdmin()->create();
    signedPaymentNotification(paymentNotificationPayload($deal->verifiedPayment))->assertOk()->assertJsonPath('result', 'refund_review');

    signInUser($approver);
    $show = route('deals.show', $deal);
    submitRendered($this->get($show), route('disputes.store', $deal), 'Open settlement dispute', ['description' => 'The receiving treasury reports a discrepancy.'])->assertSee('Dispute under operations review');
    $dispute = DealDispute::query()->sole();

    signInUser($operator);
    $receipt = PaymentReceipt::query()->where('status', 'refund_review')->sole();
    submitRendered(openFromHome('Review payment exception'), route('payments.receipts.review', $receipt), 'Review receipt', ['reason' => 'Review the additional synthetic receipt.'])->assertSee('Record demo refund confirmation');
    $page = $this->post(route('payments.receipts.review', $receipt), ['reason' => 'Review the additional synthetic receipt.']);
    submitRendered($page, route('payments.receipts.refund', $receipt), 'Record refund confirmation', ['refund_reference' => 'DEMO-REFUND-1', 'reason' => 'Synthetic exception reviewed; no transfer made.']);

    signInUser($maker);
    $correction = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->borrower_bank_id]);
    submitRendered($this->get($show), route('deal-instructions.store', $deal), 'Propose instruction update', ['settlement_instruction_id' => $correction->id])->assertSee('Instruction update pending');
    signInUser($approver);
    $change = $deal->pendingInstructionChange()->sole();
    submitRendered(openFromHome('Review instruction update'), route('deal-instructions.review', ['deal' => $deal, 'change' => $change]), 'Approve instruction update')->assertSee('Settlement instructions were independently reviewed and updated.');

    signInUser($operator);
    submitRendered(openFromHome('Resolve settlement dispute'), route('disputes.review', $dispute), 'Review dispute', ['reason' => 'Inspect the settlement evidence.'])->assertSee('Close without confirmed settlement');
    $review = $this->post(route('disputes.review', $dispute), ['reason' => 'Inspect the settlement evidence.']);
    submitRendered($review, route('disputes.resolve', $dispute), 'Close without confirmed settlement', ['reason' => 'Neither institution could confirm.']);
    expect($deal->fresh()->status)->toBe(DealStatus::ClosedUnsettled);

    signInUser($approver);
    $this->get($show)->assertOk()->assertSee('Closed without settlement');
    submitRendered($this->get(route('notifications.index')), route('notifications.read-all'), 'Mark all as read')->assertDontSee('Mark all as read');
});

it('cancels a request, withdraws an offer and retires instructions through the rendered screens', function () {
    $maker = bankStaff(BankRole::Maker);
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
    signInUser($maker);
    submitRendered($this->get(route('funding.show', $request)), route('funding.cancel', $request), 'Send cancellation for approval', ['reason' => 'Liquidity need has been met.'])->assertSee('Cancellation approval pending');
    signInUser($approver);
    submitRendered(openFromHome('Approve cancellation'), route('funding.review', ['fundingRequest' => $request, 'approval' => $request->pendingApproval]), 'Approve cancellation')->assertSee('This request was cancelled');

    $open = FundingRequest::factory()->published()->create();
    $lender = bankStaff(BankRole::Maker);
    $offer = marketOffer($lender, $open);
    signInUser($lender);
    submitRendered($this->get(route('offers.show', $offer)), route('offers.withdraw', $offer), 'Withdraw offer', ['reason' => 'Terms need to change.'])->assertSee('This offer is withdrawn');

    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $approver->membership->bank_id]);
    signInUser($approver);
    submitRendered($this->get(route('instructions.show', $instruction)), route('instructions.retire', $instruction), 'Retire version', ['reason' => 'Account closed.'])->assertSee('Retired');
});

function openFromHomeSetup(string $task): void
{
    $home = test()->get(route('dashboard'))->assertOk();
    expect(renderedDom($home)->query('//section[contains(@class, "next-step")]//h2[normalize-space(.)="'.$task.'"]')->length)->toBe(1);
}
