<?php

use App\Actions\ManageFeePayments;
use App\BankRole;
use App\DealStatus;
use App\FeePaymentStatus;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\PaymentReceiptStatus;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('requires platform operations and an audited reason to inspect private payment references', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment, ['amount' => '1.00']))->assertOk();
    $receipt = PaymentReceipt::query()->sole();
    signInUser(User::factory()->platformAdmin()->create());
    $this->postJson(route('payments.receipts.review', $receipt))->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson(route('payments.receipts.review', $receipt), ['reason' => 'Investigating received amount mismatch.'])->assertOk()->assertJsonPath('data.expectedAmount', '250000.00');
    $this->assertDatabaseHas('audit_events', ['action' => 'fee.receipt_reviewed', 'bank_id' => $payment->bank_id]);
    signInUser(User::query()->findOrFail($payment->initiated_by));
    $this->postJson(route('payments.receipts.review', $receipt), ['reason' => 'Trying platform review route.'])->assertNotFound();
});

it('records a refund confirmation once and permits a fresh attempt without unlocking the failed payment', function () {
    $payment = feePaymentAttempt();
    $payload = paymentNotificationPayload($payment, ['amount' => '1.00']);
    signedPaymentNotification($payload)->assertOk();
    $receipt = PaymentReceipt::query()->sole();
    $approver = User::query()->findOrFail($payment->initiated_by);
    signInUser($approver);
    $this->postJson(route('api.payments.store', $payment->deal))->assertConflict();
    signInUser(User::factory()->platformAdmin()->create());
    $data = ['refund_reference' => 'DEMO-REFUND-1001', 'reason' => 'Independently reconciled and refund confirmed.'];
    $this->postJson(route('payments.receipts.refund', $receipt), $data)->assertOk()->assertJsonPath('data.status', 'refunded');
    $this->postJson(route('payments.receipts.refund', $receipt), $data)->assertOk();
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Refunded);
    expect($payment->deal->fresh()->status)->toBe(DealStatus::AwaitingFee);
    expect($payment->deal->fresh()->unlocked_at)->toBeNull();
    $newPayment = app(ManageFeePayments::class)->initiate($approver, $payment->deal->fresh());
    expect($newPayment->id)->not->toBe($payment->id);
    signedPaymentNotification(array_replace($payload, ['event_reference' => (string) Str::uuid()]))->assertOk()->assertJsonPath('result', 'duplicate');
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeFalse();
});

it('does not permit borrower bank admins or approvers to record refunds', function (BankRole $role) {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment, ['amount' => '1.00']))->assertOk();
    $receipt = PaymentReceipt::query()->sole();
    $user = bankStaff($role, $payment->deal->borrowerBank);
    signInUser($user);

    $this->postJson(route('payments.receipts.refund', $receipt), ['refund_reference' => 'FAKE-REFUND', 'reason' => 'Attempting an unauthorized refund record.'])->assertForbidden();
    expect($receipt->fresh()->status)->toBe(PaymentReceiptStatus::RefundReview);
})->with([BankRole::Admin, BankRole::Approver]);

it('records unmatched receipt refunds without assigning the funds to a deal', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment, ['payment_reference' => 'XFP-unknown-provider-reference']))->assertOk();
    $receipt = PaymentReceipt::query()->sole();
    signInUser(User::factory()->platformAdmin()->create());

    $this->postJson(route('payments.receipts.refund', $receipt), ['refund_reference' => 'UNMATCHED-REFUND', 'reason' => 'Original provider refund independently reconciled.'])->assertOk()->assertJsonPath('data.status', 'refunded');
    expect($receipt->fresh()->fee_payment_id)->toBeNull();
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeFalse();
});

it('does not undo the original unlock when an additional charge is refunded', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    $extra = PaymentReceipt::query()->where('status', PaymentReceiptStatus::RefundReview)->sole();
    signInUser(User::factory()->platformAdmin()->create());

    $this->postJson(route('payments.receipts.refund', $extra), ['refund_reference' => 'EXTRA-REFUND-1002', 'reason' => 'Additional charge refunded after reconciliation.'])->assertOk();
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Verified);
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeTrue();
});

it('does not reuse one refund reference on another receipt with 409', function () {
    $first = feePaymentAttempt();
    $second = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($first, ['amount' => '1.00']))->assertOk();
    signedPaymentNotification(paymentNotificationPayload($second, ['amount' => '1.00']))->assertOk();
    $receipts = PaymentReceipt::query()->orderBy('id')->get();
    signInUser(User::factory()->platformAdmin()->create());
    $data = ['refund_reference' => 'UNIQUE-REFUND-1003', 'reason' => 'Refund confirmation independently reviewed.'];
    $this->postJson(route('payments.receipts.refund', $receipts[0]), $data)->assertOk();
    $this->postJson(route('payments.receipts.refund', $receipts[1]), $data)->assertConflict();
    expect($receipts[1]->fresh()->status)->toBe(PaymentReceiptStatus::RefundReview);
});

it('renders the operations exception screens and escapes refund notes', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment, ['amount' => '1.00']))->assertOk();
    $receipt = PaymentReceipt::query()->sole();
    $dangerous = '<script>alert("refund")</script>';
    $receipt->forceFill(['status' => PaymentReceiptStatus::Refunded, 'refund_reference' => $dangerous, 'review_reason' => $dangerous])->save();
    signInUser(User::factory()->platformAdmin()->create());

    $this->get(route('payments.operations'))->assertOk()->assertSee('Review payment exceptions.');
    $this->post(route('payments.receipts.review', $receipt), ['reason' => 'Rechecking the recorded refund evidence.'])->assertOk()->assertSee(e($dangerous), false)->assertDontSee($dangerous, false);
});
