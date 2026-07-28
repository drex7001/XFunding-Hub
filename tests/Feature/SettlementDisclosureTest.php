<?php

use App\Actions\ManageFeePayments;
use App\BankRole;
use App\BankStatus;
use App\DealStatus;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('discloses only the selected approved lender after verified payment and audits each read', function () {
    $payment = feePaymentAttempt();
    $deal = $payment->deal;
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    $borrower = User::query()->findOrFail($payment->initiated_by);
    signInUser($borrower);

    $response = $this->getJson(route('api.deals.settlement', $deal))->assertOk()
        ->assertJsonPath('data.lender.name', $deal->instruction_snapshot['bank']['name'])
        ->assertJsonPath('data.instructions.accountNumber', 'DEMO000012345678')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('data'))->not->toHaveKey('lender_bank_id');
    $this->get(route('deals.settlement', $deal))->assertOk()->assertSee('DEMO000012345678');
    expect(AuditEvent::query()->where('action', 'deal.settlement_details_viewed')->count())->toBe(2);
    $this->assertDatabaseHas('audit_events', ['action' => 'deal.settlement_details_viewed', 'actor_id' => $borrower->id, 'bank_id' => $borrower->membership->bank_id]);
});

it('requires treasury roles for settlement disclosure even after payment', function (BankRole $role) {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    $deal = $payment->deal->fresh();
    $user = bankStaff($role, $deal->borrowerBank);
    $allowed = in_array($role, [BankRole::Maker, BankRole::Approver], true);
    expect(Gate::forUser($user)->allows('viewSettlement', $deal))->toBe($allowed);
    signInUser($user);
    if ($allowed) {
        $this->getJson(route('api.deals.settlement', $deal))->assertOk();
    } else {
        $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
        $this->get(route('deals.show', $deal))->assertOk()->assertDontSee('DEMO000012345678')->assertDontSee('View settlement details →');
    }
})->with(BankRole::cases());

it('does not disclose before payment or trust an unlocked status without verification proof with 403', function () {
    $borrower = bankStaff();
    $deal = paymentDeal($borrower);
    signInUser($borrower);
    $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
    $deal->forceFill(['status' => DealStatus::Unlocked, 'unlocked_at' => now()])->save();

    $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
    $this->assertDatabaseMissing('audit_events', ['action' => 'deal.settlement_details_viewed']);
});

it('rejects fee verification proof copied from a different deal with 403', function () {
    $paid = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($paid))->assertOk();
    $borrower = bankStaff();
    $deal = paymentDeal($borrower);
    $deal->forceFill(['status' => DealStatus::Unlocked, 'unlocked_at' => now(), 'verified_payment_id' => $paid->id])->save();
    signInUser($borrower);

    $this->getJson(route('api.deals.settlement', $deal))->assertForbidden();
});

it('hides selected settlement details from unrelated lenders and platform operations with 404', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    signInUser(bankStaff());
    $this->getJson(route('api.deals.settlement', $payment->deal))->assertNotFound();
    signInUser(User::factory()->platformAdmin()->create());
    $this->getJson(route('api.deals.settlement', $payment->deal))->assertNotFound();
});

it('rechecks lender eligibility and membership on each settlement read', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    $borrower = User::query()->findOrFail($payment->initiated_by);
    signInUser($borrower);
    $payment->deal->lenderBank->forceFill(['status' => BankStatus::Suspended])->save();
    $this->getJson(route('api.deals.settlement', $payment->deal))->assertForbidden();
    $borrower->membership->update(['active' => false]);
    $this->getJson(route('api.deals.settlement', $payment->deal))->assertForbidden();
});

it('keeps account fields out of ordinary deal payloads after unlock', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    signInUser(User::query()->findOrFail($payment->initiated_by));

    $this->getJson(route('api.deals.show', $payment->deal))->assertOk()->assertJsonPath('data.identityUnlocked', true)
        ->assertJsonPath('data.can.viewSettlement', true)->assertJsonMissingPath('data.lender')->assertJsonMissingPath('data.instructions');
});

it('escapes approved account and contact text in the settlement view', function () {
    $borrower = bankStaff(BankRole::Approver);
    $deal = paymentDeal($borrower);
    $dangerous = '<script>alert("settlement")</script>';
    $snapshot = $deal->instruction_snapshot;
    $snapshot['details']['account_name'] = $dangerous;
    $snapshot['details']['contact_name'] = $dangerous;
    $deal->settlementInstruction->forceFill(['details' => $snapshot['details']])->save();
    $deal->forceFill(['instruction_snapshot' => $snapshot])->save();
    $payment = app(ManageFeePayments::class)->initiate($borrower, $deal);
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    signInUser($borrower);

    $this->get(route('deals.settlement', $deal))->assertOk()->assertSee(e($dangerous), false)->assertDontSee($dangerous, false);
});

it('keeps the deal list scoped to participants and its query count bounded', function () {
    $borrower = bankStaff(BankRole::Approver);
    paymentDeal($borrower);
    paymentDeal($borrower);
    paymentDeal(bankStaff());
    signInUser($borrower);
    DB::enableQueryLog();
    $this->getJson(route('api.deals.index'))->assertOk()->assertJsonCount(2, 'data');
    $initial = count(DB::getQueryLog());
    for ($index = 0; $index < 6; $index++) {
        paymentDeal($borrower);
    }
    DB::flushQueryLog();
    $this->getJson(route('api.deals.index'))->assertOk()->assertJsonCount(8, 'data');
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expanded)->toBeLessThanOrEqual($initial + 2);
});
