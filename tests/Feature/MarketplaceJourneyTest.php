<?php

use App\BankRole;
use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\FeePayment;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('completes a two-lender request through verified fee and both bank attestations using authorized HTTP actions', function (int $winnerIndex) {
    $this->freezeTime();
    enableDemoPayments();
    Http::preventStrayRequests();
    Storage::fake('private');
    $borrowerMaker = bankStaff();
    $borrowerApprover = bankStaff(BankRole::Approver, $borrowerMaker->membership->bank);
    $lenders = [bankStaff(), bankStaff()];
    $lenderApprovers = array_map(fn (User $maker): User => bankStaff(BankRole::Approver, $maker->membership->bank), $lenders);
    $operations = User::factory()->platformAdmin()->create();

    signInUser($borrowerMaker);
    $requestId = $this->postJson(route('api.funding.store'), fundingPayload())->assertCreated()->json('data.id');
    $this->post(route('funding.documents.store', $requestId), [
        'document' => UploadedFile::fake()->createWithContent('synthetic-audit.pdf', "%PDF-1.4\nSynthetic bank audit evidence\n%%EOF\n"),
    ])->assertRedirect();
    $request = FundingRequest::query()->findOrFail($requestId);
    $document = $request->documents()->sole();
    $this->postJson(route('api.funding.submit', $request))->assertOk()->assertJsonPath('data.status', 'pending_approval');
    signInUser($borrowerApprover);
    $this->postJson(route('api.funding.review', [$request, $request->approvals()->sole()]), ['decision' => 'approve'])
        ->assertOk()->assertJsonPath('data.status', 'open');

    $offers = [];
    foreach ($lenders as $index => $maker) {
        signInUser($maker);
        $this->getJson(route('api.marketplace'))->assertOk()->assertJsonCount(1, 'data');
        $this->get(route('funding.documents.show', $document))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $instructionId = $this->postJson(route('api.instructions.store'), instructionPayload([
            'account_number' => 'SECRETDEMOACCOUNT'.$index,
            'contact_email' => 'private-desk-'.$index.'@synthetic.example',
        ]))->assertCreated()->json('data.id');
        signInUser($lenderApprovers[$index]);
        $this->postJson(route('api.instructions.review', $instructionId), ['decision' => 'approve'])->assertOk();
        $instruction = SettlementInstruction::query()->findOrFail($instructionId);
        signInUser($maker);
        $offerId = $this->postJson(route('api.offers.store', $request), offerPayload($instruction, $request, [
            'rate' => $index === 0 ? '8.1250' : '7.9000',
            'collateral' => $index === 0 ? 'unsecured' : 'sovereign_bonds',
        ]))->assertCreated()->json('data.id');
        $this->postJson(route('api.offers.submit', $offerId))->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $offer = Offer::query()->findOrFail($offerId);
        signInUser($lenderApprovers[$index]);
        $this->postJson(route('api.offers.review', [$offer, $offer->pendingApproval]), ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $offers[] = $offer;
    }

    signInUser($borrowerMaker);
    $comparison = $this->getJson(route('api.funding.offers', $request))->assertOk()->assertJsonCount(2, 'data.offers');
    foreach ($lenders as $index => $maker) {
        expect($comparison->getContent())->not->toContain($maker->membership->bank->name)
            ->not->toContain('SECRETDEMOACCOUNT'.$index)->not->toContain($offers[$index]->id);
    }
    $token = $comparison->json('data.offers.'.$winnerIndex.'.token');
    $selectionId = $this->postJson(route('api.selection.store', $request), ['offer_token' => $token])->assertCreated()->json('data.id');
    signInUser($borrowerApprover);
    $selection = $this->postJson(route('api.selection.review', [$request, $selectionId]), ['decision' => 'approve'])
        ->assertOk()->assertJsonPath('data.status', 'awaiting_fee')->assertJsonPath('data.fee.amount', '250000.00');
    $deal = Deal::query()->findOrFail($selection->json('data.id'));
    $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
    expect($offers[1 - $winnerIndex]->fresh()->status)->toBe(OfferStatus::Unsuccessful);

    $firstPaymentId = $this->postJson(route('api.payments.store', $deal))->assertCreated()->json('data.id');
    $firstPayment = FeePayment::query()->findOrFail($firstPaymentId);
    signedPaymentNotification(paymentNotificationPayload($firstPayment, ['amount' => '1.00']))->assertOk()->assertJsonPath('result', 'refund_review');
    $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
    $receipt = $firstPayment->receipts()->sole();
    signInUser($operations);
    $this->postJson(route('payments.receipts.review', $receipt), ['reason' => 'Review synthetic wrong-amount receipt.'])->assertOk();
    $this->postJson(route('payments.receipts.refund', $receipt), [
        'refund_reference' => 'DEMO-MANUAL-REFUND-'.$winnerIndex, 'reason' => 'Synthetic exception reviewed; no real refund transfer.',
    ])->assertOk()->assertJsonPath('data.status', 'refunded');

    signInUser($borrowerApprover);
    $paymentId = $this->postJson(route('api.payments.store', $deal))->assertCreated()->json('data.id');
    $payment = FeePayment::query()->findOrFail($paymentId);
    $payload = paymentNotificationPayload($payment);
    signedPaymentNotification($payload)->assertOk()->assertJsonPath('result', 'verified');
    signedPaymentNotification($payload)->assertOk()->assertJsonPath('result', 'duplicate');
    $this->getJson(route('api.deals.settlement', $deal))->assertOk()
        ->assertJsonPath('data.instructions.accountNumber', 'SECRETDEMOACCOUNT'.$winnerIndex);
    $ordinary = $this->getJson(route('api.deals.show', $deal))->assertOk()->assertJsonPath('data.status', 'unlocked');
    expect($ordinary->getContent())->not->toContain('SECRETDEMOACCOUNT')->not->toContain($lenders[$winnerIndex]->membership->bank->name);
    signInUser($lenders[1 - $winnerIndex]);
    $this->get(route('funding.documents.show', $document))->assertNotFound();
    $this->getJson(route('api.deals.show', $deal))->assertNotFound();

    foreach ([[$borrowerMaker, $borrowerApprover], [$lenders[$winnerIndex], $lenderApprovers[$winnerIndex]]] as $index => [$maker, $approver]) {
        signInUser($maker);
        $id = $this->postJson(route('api.confirmations.store', $deal), settlementPayload())->assertCreated()->json('data.id');
        signInUser($approver);
        $this->postJson(route('api.confirmations.review', [$deal, $id]), ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', $index === 0 ? 'unlocked' : 'settled');
    }
    expect($deal->fresh()->status)->toBe(DealStatus::Settled);
    expect($request->fresh()->status)->toBe(FundingRequestStatus::Settled);
    expect($payment->receipts()->where('status', 'verified')->count())->toBe(1);

    foreach (BankRole::cases() as $role) {
        signInUser(bankStaff($role, $borrowerMaker->membership->bank));
        $this->getJson(route('api.deals.index'))->assertOk()->assertJsonCount(1, 'data');
        $this->get(route('deals.show', $deal))->assertOk()->assertDontSee('SECRETDEMOACCOUNT');
        $timeline = $this->getJson(route('api.deals.timeline', $deal))->assertOk();
        $notifications = $this->getJson(route('api.notifications.index'))->assertOk();
        foreach ([$timeline, $notifications] as $response) {
            expect($response->getContent())->not->toContain('SECRETDEMOACCOUNT')->not->toContain('SYNTHETIC-OFFPLATFORM-REF')
                ->not->toContain($lenders[$winnerIndex]->membership->bank->name);
        }
        $disclosure = $this->getJson(route('api.deals.settlement', $deal));
        if (in_array($role, [BankRole::Maker, BankRole::Approver], true)) {
            $disclosure->assertOk();
        } else {
            $disclosure->assertForbidden();
        }
    }
})->with(['first lender wins' => 0, 'second lender wins' => 1])->group('marketplace-journey');
