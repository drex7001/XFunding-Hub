<?php

use App\Actions\ManageFeePayments;
use App\Actions\RecordAuditEvent;
use App\BankStatus;
use App\DealStatus;
use App\FeePaymentStatus;
use App\Models\AuditEvent;
use App\Models\Deal;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\PaymentReceiptStatus;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('atomically verifies the exact signed fee and unlocks the selected deal', function () {
    $payment = feePaymentAttempt();

    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk()->assertJsonPath('result', 'verified');

    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Verified);
    expect($payment->fresh()->pending_slot)->toBeNull();
    expect($payment->deal->fresh()->status)->toBe(DealStatus::Unlocked);
    expect($payment->deal->fresh()->verified_payment_id)->toBe($payment->id);
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeTrue();
    $this->assertDatabaseCount('payment_receipts', 1);
    $this->assertDatabaseCount('payment_notifications', 1);
    $this->assertDatabaseHas('audit_events', ['action' => 'deal.unlocked', 'bank_id' => $payment->bank_id]);
});

it('does not trust an authenticated borrower or an unsigned paid message with 401', function () {
    $payment = feePaymentAttempt();
    signInUser(User::query()->findOrFail($payment->initiated_by));

    $this->postJson(route('payments.demo.notify'), paymentNotificationPayload($payment))->assertUnauthorized();
    signedPaymentNotification(paymentNotificationPayload($payment), ['HTTP_X_PAYMENT_SIGNATURE' => str_repeat('0', 64)])->assertUnauthorized();
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Pending);
    $this->assertDatabaseCount('payment_receipts', 0);
});

it('rejects a correctly signed stale notification timestamp with 401', function () {
    $payment = feePaymentAttempt();
    $payload = paymentNotificationPayload($payment);
    $timestamp = (string) now()->subMinutes(6)->getTimestamp();
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    signedPaymentNotification($payload, ['HTTP_X_PAYMENT_TIMESTAMP' => $timestamp, 'HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, config('payments.webhook_secret'))])->assertUnauthorized();
    $this->assertDatabaseCount('payment_notifications', 0);
});

it('rejects malformed signed provider fields with 422', function (array $overrides, string $field) {
    $payment = feePaymentAttempt();

    signedPaymentNotification(paymentNotificationPayload($payment, $overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('payment_receipts', 0);
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Pending);
})->with([
    'numeric amount' => [['amount' => 250000], 'amount'], 'zero amount' => [['amount' => '0.00'], 'amount'],
    'extra precision' => [['amount' => '250000.001'], 'amount'], 'bad currency' => [['currency' => 'usd'], 'currency'],
    'unknown outcome' => [['outcome' => 'success-page'], 'outcome'], 'missing charge' => [['charge_reference' => null], 'charge_reference'],
    'bad event ID' => [['event_reference' => 'fake'], 'event_reference'],
]);

it('holds mismatched or ineligible payments for refund review without unlocking', function (string $case) {
    $payment = feePaymentAttempt();
    $deal = $payment->deal;
    $payload = paymentNotificationPayload($payment);
    switch ($case) {
        case 'wrong amount': $payload['amount'] = '1.00';
            break;
        case 'wrong currency': $payload['currency'] = 'USD';
            break;
        case 'wrong deal': $payload['deal_id'] = Deal::factory()->quoted()->create()->id;
            break;
        case 'late payment': $deal->forceFill(['fee_deadline' => now()])->save();
            break;
        case 'lender suspended': $deal->lenderBank->forceFill(['status' => BankStatus::Suspended])->save();
            break;
        case 'borrower unverified': $deal->borrowerBank->forceFill(['status' => BankStatus::Pending])->save();
            break;
        case 'lender capability revoked': $deal->lenderBank->forceFill(['can_lend' => false])->save();
            break;
        case 'instruction identity changed': $deal->lenderBank->update(['name' => 'Changed lender identity']);
            break;
    }

    signedPaymentNotification($payload)->assertOk()->assertJsonPath('result', 'refund_review');
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::RefundReview);
    expect($deal->fresh()->hasVerifiedFee())->toBeFalse();
    expect($deal->fresh()->unlocked_at)->toBeNull();
    expect(PaymentReceipt::query()->sole()->status)->toBe(PaymentReceiptStatus::RefundReview);
    $this->assertDatabaseMissing('audit_events', ['action' => 'deal.unlocked']);
})->with(['wrong amount', 'wrong currency', 'wrong deal', 'late payment', 'lender suspended', 'borrower unverified', 'lender capability revoked', 'instruction identity changed']);

it('processes a repeated event and repeated charge only once', function () {
    $payment = feePaymentAttempt();
    $payload = paymentNotificationPayload($payment);
    signedPaymentNotification($payload)->assertOk()->assertJsonPath('result', 'verified');
    signedPaymentNotification($payload)->assertOk()->assertJsonPath('result', 'duplicate');
    signedPaymentNotification(array_replace($payload, ['event_reference' => (string) Str::uuid()]))->assertOk()->assertJsonPath('result', 'duplicate');

    $this->assertDatabaseCount('payment_receipts', 1);
    $this->assertDatabaseCount('payment_notifications', 2);
    expect(AuditEvent::query()->where('action', 'deal.unlocked')->count())->toBe(1);
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeTrue();
});

it('rejects changed contents for a previously processed event with 409', function () {
    $payment = feePaymentAttempt();
    $payload = paymentNotificationPayload($payment);
    signedPaymentNotification($payload)->assertOk();

    signedPaymentNotification(array_replace($payload, ['amount' => '1.00']))->assertConflict();
    $this->assertDatabaseCount('payment_receipts', 1);
    $this->assertDatabaseCount('payment_notifications', 1);
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Verified);
});

it('reviews an additional charge without removing the original verified fee', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk()->assertJsonPath('result', 'refund_review');

    $this->assertDatabaseCount('payment_receipts', 2);
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Verified);
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeTrue();
    expect(PaymentReceipt::query()->where('status', PaymentReceiptStatus::RefundReview)->sole()->reason)->toBe('additional_charge');
});

it('cannot allocate one provider charge to two deals', function () {
    $first = feePaymentAttempt();
    $second = feePaymentAttempt();
    $payload = paymentNotificationPayload($first);
    signedPaymentNotification($payload)->assertOk();

    signedPaymentNotification(paymentNotificationPayload($second, ['charge_reference' => $payload['charge_reference']]))->assertOk()->assertJsonPath('result', 'charge_conflict');
    expect($second->deal->fresh()->hasVerifiedFee())->toBeFalse();
    expect($second->fresh()->status)->toBe(FeePaymentStatus::Pending);
    expect(PaymentReceipt::query()->sole()->fee_payment_id)->toBe($first->id);
});

it('records unmatched funds and never reallocates their charge automatically', function () {
    $payment = feePaymentAttempt();
    $payload = paymentNotificationPayload($payment, ['payment_reference' => 'XFP-'.Str::uuid()]);
    signedPaymentNotification($payload)->assertOk()->assertJsonPath('result', 'refund_review');
    expect(PaymentReceipt::query()->sole()->fee_payment_id)->toBeNull();

    signedPaymentNotification(paymentNotificationPayload($payment, ['charge_reference' => $payload['charge_reference']]))->assertOk()->assertJsonPath('result', 'charge_conflict');
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeFalse();
});

it('permits a new attempt after provider failure but does not revive the old attempt', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment, ['outcome' => 'failed', 'charge_reference' => null]))->assertOk()->assertJsonPath('result', 'failed');
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Failed);
    $approver = User::query()->findOrFail($payment->initiated_by);
    $newPayment = app(ManageFeePayments::class)->initiate($approver, $payment->deal);
    expect($newPayment->id)->not->toBe($payment->id);

    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk()->assertJsonPath('result', 'refund_review');
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeFalse();
    expect($newPayment->fresh()->status)->toBe(FeePaymentStatus::Pending);
});

it('ignores delayed failure messages after a verified payment', function () {
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();

    signedPaymentNotification(paymentNotificationPayload($payment, ['outcome' => 'failed', 'charge_reference' => null]))->assertOk()->assertJsonPath('result', 'ignored');
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Verified);
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeTrue();
});

it('keeps a cancelled deal cancelled when funds arrive after the expiry worker', function () {
    $payment = feePaymentAttempt();
    $payment->deal->forceFill(['fee_deadline' => now()])->save();
    $this->artisan('deals:expire-fees')->assertSuccessful();

    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk()->assertJsonPath('result', 'refund_review');
    expect($payment->deal->fresh()->status)->toBe(DealStatus::Cancelled);
    expect($payment->deal->fresh()->verified_payment_id)->toBeNull();
});

it('rolls back receipt verification and unlock together when persistence fails', function () {
    $payment = feePaymentAttempt();
    $audit = Mockery::mock(RecordAuditEvent::class);
    $audit->shouldReceive('handle')->andThrow(new RuntimeException('Audit storage unavailable.'));
    app()->instance(RecordAuditEvent::class, $audit);
    $this->withoutExceptionHandling();

    expect(fn () => signedPaymentNotification(paymentNotificationPayload($payment)))->toThrow(RuntimeException::class, 'Audit storage unavailable.');
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Pending);
    expect($payment->deal->fresh()->status)->toBe(DealStatus::AwaitingFee);
    expect($payment->deal->fresh()->unlocked_at)->toBeNull();
    $this->assertDatabaseCount('payment_receipts', 0);
    $this->assertDatabaseCount('payment_notifications', 0);
});

it('disables the synthetic provider in production even if its flag is enabled with 404', function () {
    $payment = feePaymentAttempt();
    app()->instance('env', 'production');

    signedPaymentNotification(paymentNotificationPayload($payment))->assertNotFound();
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Pending);
    $this->artisan('payments:simulate', ['payment' => $payment->id])->assertFailed();
});
