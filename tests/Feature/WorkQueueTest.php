<?php

use App\Actions\BuildWorkQueue;
use App\Actions\ChangeDealInstructions;
use App\Actions\ManageDealDisputes;
use App\Actions\ManageFeePayments;
use App\Actions\ManageSettlementConfirmations;
use App\Actions\SelectOffer;
use App\BankRole;
use App\BankStatus;
use App\Models\AccessRequest;
use App\Models\Bank;
use App\Models\FundingRequest;
use App\Models\FundingRequestApproval;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;

beforeEach(function () {
    enableDemoPayments();
});

/** @return array<int, string> */
function tasksFor(User $user, string $list = 'actions'): array
{
    return array_column(app(BuildWorkQueue::class)->for($user->fresh(['membership.bank']))[$list], 'task');
}

it('guides a maker from an empty draft to submitting it and comparing offers', function () {
    $maker = bankStaff(BankRole::Maker);
    $request = fundingDraft($maker);
    expect(tasksFor($maker))->toContain('Attach financial documents');

    financialEvidence($request);
    expect(tasksFor($maker))->toContain('Submit for approval')->not->toContain('Attach financial documents');

    $open = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
    expect(tasksFor($maker))->not->toContain('Compare offers');
    marketOffer(bankStaff(), $open);
    expect(tasksFor($maker))->toContain('Compare offers');
});

it('gives publication approval only to an independent approver', function () {
    $maker = bankStaff(BankRole::Maker);
    $bank = $maker->membership->bank;
    $request = fundingDraft($maker, ['status' => 'pending_approval']);
    FundingRequestApproval::factory()
        ->create([
            'funding_request_id' => $request->id,
            'proposed_by' => $maker->id,
            'expected_version' => $request->version,
            'kind' => 'publish',
        ]);
    $independent = bankStaff(BankRole::Approver, $bank);
    $contributor = bankStaff(BankRole::Approver, $bank);
    $request->forceFill(['contributor_ids' => [$maker->id, $contributor->id]])->save();

    expect(tasksFor($independent))->toContain('Approve publication')
        ->and(tasksFor($contributor))->not->toContain('Approve publication')
        ->and(tasksFor($contributor, 'waiting'))->toContain('Approve publication')
        ->and(tasksFor($maker, 'waiting'))->toContain('Approve publication');
});

it('never asks anyone to approve their own selection, even with both roles', function () {
    $proposer = bankStaff(BankRole::Maker);
    $proposer->membership->update(['roles' => [BankRole::Maker->value, BankRole::Approver->value]]);
    $proposer = $proposer->fresh(['membership.bank']);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $proposer->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    app(SelectOffer::class)->propose($proposer, $request, $offer->borrower_token, receivingInstructionId($request));

    expect(tasksFor($proposer))->not->toContain('Approve offer selection')
        ->and(tasksFor($proposer, 'waiting'))->toContain('Approve offer selection')
        ->and(tasksFor(bankStaff(BankRole::Approver, $proposer->membership->bank)))
        ->toContain('Approve offer selection');
});

it('routes offer drafts to makers and approvals to independent lender approvers', function () {
    $maker = bankStaff(BankRole::Maker);
    $request = FundingRequest::factory()->published()->create();
    $offer = Offer::factory()
        ->create([
            'bank_id' => $maker->membership->bank_id,
            'created_by' => $maker->id,
            'funding_request_id' => $request->id,
            'contributor_ids' => [$maker->id],
        ]);
    expect(tasksFor($maker))->toContain('Send offer for approval');

    $offer->forceFill(['status' => OfferStatus::PendingApproval])->save();
    OfferApproval::factory()->create(['offer_id' => $offer->id, 'proposed_by' => $maker->id, 'expected_version' => 1]);

    expect(tasksFor($maker))->not->toContain('Send offer for approval')
        ->and(tasksFor($maker, 'waiting'))->toContain('Approve offer')
        ->and(tasksFor(bankStaff(BankRole::Approver, $maker->membership->bank)))->toContain('Approve offer');
});

it('asks treasury staff to set up instructions and an independent approver to approve them', function () {
    $maker = bankStaff(BankRole::Maker);
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    expect(tasksFor($maker, 'setup'))->toContain('Add settlement instructions');

    $instruction = SettlementInstruction::factory()
        ->create(['bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id]);
    expect(tasksFor($maker, 'setup'))->toBe([])
        ->and(tasksFor($maker, 'waiting'))->toContain('Approve settlement instructions')
        ->and(tasksFor($approver))->toContain('Approve settlement instructions');

    $instruction->forceFill(['status' => 'approved'])->save();
    expect(tasksFor($maker, 'setup'))
        ->toBe([])
        ->and(tasksFor($approver))->not->toContain('Approve settlement instructions');
});

it('walks each bank through the fee and settlement steps of a deal', function () {
    $borrowerApprover = bankStaff(BankRole::Approver);
    $deal = paymentDeal($borrowerApprover);
    $lenderMaker = bankStaff(BankRole::Maker, $deal->lenderBank);
    $lenderApprover = bankStaff(BankRole::Approver, $deal->lenderBank);
    $borrowerMaker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    expect(tasksFor($borrowerApprover))->toContain('Pay platform fee')
        ->and(tasksFor($borrowerMaker))->not->toContain('Pay platform fee')
        ->and(tasksFor($lenderMaker, 'waiting'))->toContain('Platform fee');

    $this->actingAs($borrowerApprover);
    $payment = app(ManageFeePayments::class)->initiate($borrowerApprover, $deal);
    expect(tasksFor($borrowerApprover))->not->toContain('Pay platform fee');
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();

    expect(tasksFor($lenderMaker))->toContain('Send principal and confirm')
        ->and(tasksFor($borrowerMaker))->toContain('Confirm principal received')
        ->and(tasksFor($lenderApprover))->not->toContain('Send principal and confirm');

    $confirmation = app(ManageSettlementConfirmations::class)
        ->propose($lenderMaker, $deal->fresh(), settlementPayload());
    expect(tasksFor($lenderApprover))->toContain('Review settlement confirmation')
        ->and(tasksFor($lenderMaker))->not->toContain('Send principal and confirm')
        ->and(tasksFor($lenderMaker, 'waiting'))->toContain('Settlement confirmation');

    app(ManageSettlementConfirmations::class)->review($lenderApprover, $confirmation, true, null);
    expect(tasksFor($lenderApprover))->not->toContain('Review settlement confirmation')
        ->and(tasksFor($lenderApprover, 'waiting'))->toContain('Settlement');
});

it('shows an instruction review only to the side that owns the change', function () {
    $deal = paymentDeal(bankStaff());
    $maker = bankStaff(BankRole::Maker, $deal->lenderBank);
    $instruction = SettlementInstruction::factory()->approved()->create(['bank_id' => $deal->lender_bank_id]);
    app(ChangeDealInstructions::class)->propose($maker, $deal, $instruction->id);

    expect(tasksFor(bankStaff(BankRole::Approver, $deal->lenderBank)))->toContain('Review instruction update')
        ->and(
            tasksFor(bankStaff(BankRole::Approver, $deal->borrowerBank))
        )->not->toContain('Review instruction update');
});

it('never names the lender in anything the borrower sees in the queue', function () {
    $lenderBank = Bank::factory()->verified()->create(['name' => 'Hidden Lender Bank']);
    $maker = bankStaff(BankRole::Maker);
    $approver = bankStaff(BankRole::Approver, $maker->membership->bank);
    $request = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
    $offer = marketOffer(bankStaff(BankRole::Maker, $lenderBank), $request);
    $proposal = app(SelectOffer::class)
        ->propose($maker, $request, $offer->borrower_token, receivingInstructionId($request));
    app(SelectOffer::class)->review($approver, $proposal, true, null);

    foreach ([$maker, $approver] as $user) {
        signInUser($user->fresh(['membership.bank']));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Hidden Lender Bank');
    }
    expect(
        json_encode(app(BuildWorkQueue::class)->for($approver->fresh(['membership.bank'])))
    )->not->toContain('Hidden Lender Bank');
});

it('keeps another bank out of the queue', function () {
    $maker = bankStaff(BankRole::Maker);
    fundingDraft($maker);
    $otherApprover = bankStaff(BankRole::Approver);
    $otherMaker = bankStaff(BankRole::Maker);

    expect(tasksFor($otherApprover))->toBe([])->and(tasksFor($otherMaker))->toBe([]);
});

it('asks another bank admin to review access and leaves auditors with nothing to do', function () {
    $bank = Bank::factory()->verified()->create();
    $proposer = bankStaff(BankRole::Admin, $bank);
    $reviewer = bankStaff(BankRole::Admin, $bank);
    AccessRequest::factory()->create(['bank_id' => $bank->id, 'requested_by' => $proposer->id]);

    expect(tasksFor($reviewer))->toContain('Approve access request')
        ->and(tasksFor($proposer))->not->toContain('Approve access request')
        ->and(tasksFor($proposer, 'waiting'))->toContain('Approve access request')
        ->and(tasksFor(bankStaff(BankRole::Auditor, $bank)))->toBe([]);
});

it('explains onboarding to staff at an unverified bank', function () {
    $bank = Bank::factory()->create(['status' => BankStatus::Pending]);

    expect(tasksFor(bankStaff(BankRole::Admin, $bank), 'setup'))->toContain('Complete onboarding')
        ->and(tasksFor(bankStaff(BankRole::Maker, $bank), 'setup'))->toContain('Institution verification is pending');
});

it('gives platform operations its own queue without treasury work', function () {
    $operator = User::factory()->platformAdmin()->create();
    Bank::factory()->create(['status' => BankStatus::Pending]);
    $bank = Bank::factory()->verified()->create();
    AccessRequest::factory()->create(['bank_id' => $bank->id, 'requested_by' => bankStaff(BankRole::Admin, $bank)->id]);
    $deal = unlockedDeal();
    app(ManageDealDisputes::class)
        ->open(
            bankStaff(BankRole::Approver, $deal->borrowerBank),
            $deal,
            'Synthetic discrepancy pending investigation.',
        );
    signedPaymentNotification(paymentNotificationPayload($deal->verifiedPayment))->assertOk();

    expect(array_column(app(BuildWorkQueue::class)->for($operator)['actions'], 'task'))
        ->toContain('Verify institution')
        ->toContain('Review access request')
        ->toContain('Resolve settlement dispute')
        ->toContain('Review payment exception');
});

it('puts the nearest deadline first', function () {
    $maker = bankStaff(BankRole::Maker);
    $bankId = $maker->membership->bank_id;
    $late = FundingRequest::factory()
        ->published()
        ->create([
            'bank_id' => $bankId,
            'title' => 'Closes later',
            'bidding_deadline' => now()->addDays(3),
            'settlement_date' => now()->addDays(5)->toDateString(),
        ]);
    $soon = FundingRequest::factory()
        ->published()
        ->create([
            'bank_id' => $bankId,
            'title' => 'Closes soon',
            'bidding_deadline' => now()->addHours(5),
            'settlement_date' => now()->addDays(5)->toDateString(),
        ]);
    marketOffer(bankStaff(), $late);
    marketOffer(bankStaff(), $soon);

    expect(app(BuildWorkQueue::class)->for($maker->fresh(['membership.bank']))['actions'][0]['title'])
        ->toBe('Closes soon');
});

it('keeps the home page query count flat as work grows', function () {
    $approver = bankStaff(BankRole::Approver);
    $add = function () use ($approver): void {
        paymentDeal($approver);
        marketOffer(
            bankStaff(),
            FundingRequest::factory()->published()->create(['bank_id' => $approver->membership->bank_id]),
        );
    };
    $add();
    signInUser($approver);
    DB::enableQueryLog();
    $this->get(route('dashboard'))->assertOk();
    $initial = count(DB::getQueryLog());
    foreach (range(1, 4) as $index) {
        $add();
    }
    DB::flushQueryLog();
    $this->get(route('dashboard'))->assertOk();
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expanded)->toBeLessThanOrEqual($initial);
});
