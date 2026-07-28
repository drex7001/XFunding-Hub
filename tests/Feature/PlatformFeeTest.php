<?php

use App\Actions\ManageFeePayments;
use App\Actions\QuotePlatformFee;
use App\Actions\SelectOffer;
use App\BankRole;
use App\DealStatus;
use App\FeePaymentStatus;
use App\Models\Deal;
use App\Models\FeePayment;
use App\Models\FundingRequest;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('quotes the approved ten basis points in loan currency and freezes pricing', function () {
    $deal = Deal::factory()->create();
    app(QuotePlatformFee::class)->handle($deal);
    expect($deal->fresh()->fee_amount_minor)->toBe(25000000);
    expect($deal->fresh()->fee_currency)->toBe('LKR');
    expect($deal->fresh()->fee_deadline->toIso8601String())->toBe(now()->addHours(24)->toIso8601String());
    config(['payments.fee_basis_points' => 50]);
    app(QuotePlatformFee::class)->handle($deal->fresh());
    expect($deal->fresh()->fee_amount_minor)->toBe(25000000);
});

it('rounds fees upwards to an exact minor unit and caps the payment deadline at offer expiry', function () {
    $deal = Deal::factory()->create(['terms' => ['amount' => '0.01', 'currency' => 'USD'], 'offer_valid_until' => now()->addHours(2)]);
    app(QuotePlatformFee::class)->handle($deal);

    expect($deal->fresh()->fee_amount_minor)->toBe(1);
    expect($deal->fresh()->fee_currency)->toBe('USD');
    expect($deal->fresh()->fee_deadline->toIso8601String())->toBe(now()->addHours(2)->toIso8601String());
});

it('prepares a fee quote atomically with approved selection when demo payments are enabled', function () {
    $borrower = bankStaff();
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);
    $action = app(SelectOffer::class);
    $proposal = $action->propose($borrower, $request, $offer->borrower_token);
    $deal = $action->review(bankStaff(BankRole::Approver, $borrower->membership->bank), $proposal, true, null);

    expect($deal->hasFeeQuote())->toBeTrue();
    expect($deal->fee_amount_minor)->toBe(25000000);
});

it('initiates a single server priced payment attempt and returns it on a repeated request', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = paymentDeal($approver);
    signInUser($approver);

    $first = $this->postJson(route('api.payments.store', $deal))->assertCreated()->assertJsonPath('data.amount', '250000.00')->assertJsonPath('data.currency', 'LKR');
    $this->postJson(route('api.payments.store', $deal))->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $this->assertDatabaseCount('fee_payments', 1);
    expect($deal->fresh()->status)->toBe(DealStatus::AwaitingFee);
});

it('limits payment initiation to the owning borrower approver at the policy boundary', function (BankRole $role) {
    $user = bankStaff($role);
    $deal = paymentDeal($user);

    expect(Gate::forUser($user)->allows('create', [FeePayment::class, $deal]))->toBe($role === BankRole::Approver);
})->with(BankRole::cases());

it('refuses bank admin payment initiation with 403', function () {
    $admin = bankStaff(BankRole::Admin);
    $deal = paymentDeal($admin);
    signInUser($admin);

    $this->postJson(route('api.payments.store', $deal))->assertForbidden();
    $this->assertDatabaseCount('fee_payments', 0);
});

it('rejects forged payment amounts currencies and paid flags with 422', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = paymentDeal($approver);
    signInUser($approver);

    $this->postJson(route('api.payments.store', $deal), ['amount' => '1.00', 'currency' => 'USD', 'paid' => true])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'currency', 'paid']);
    $this->assertDatabaseCount('fee_payments', 0);
});

it('closes an expired reservation immediately without waiting for the scheduler with 409', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = paymentDeal($approver);
    $deal->forceFill(['fee_deadline' => now()])->save();
    signInUser($approver);

    $this->postJson(route('api.payments.store', $deal))->assertConflict();
    expect($deal->fresh()->status)->toBe(DealStatus::Cancelled);
    $this->assertDatabaseCount('fee_payments', 0);
});

it('expires fee reservations once and closes outstanding payment attempts', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = paymentDeal($approver);
    $payment = app(ManageFeePayments::class)->initiate($approver, $deal);
    $deal->forceFill(['fee_deadline' => now()])->save();

    $this->artisan('deals:expire-fees')->expectsOutput('Expired 1 fee reservation(s).')->assertSuccessful();
    $this->artisan('deals:expire-fees')->expectsOutput('Expired 0 fee reservation(s).')->assertSuccessful();
    expect($payment->fresh()->status)->toBe(FeePaymentStatus::Failed);
    expect($payment->fresh()->pending_slot)->toBeNull();
});

it('requires authentication and hides other institutions payment attempts with 404', function () {
    $payment = feePaymentAttempt();
    $this->getJson(route('api.payments.show', $payment))->assertUnauthorized();
    signInUser(bankStaff(BankRole::Approver));
    $this->getJson(route('api.payments.show', $payment))->assertNotFound();
    $this->postJson(route('api.payments.store', $payment->deal))->assertNotFound();
});

it('does not let platform operations initiate borrower payment with 404', function () {
    $payment = feePaymentAttempt();
    signInUser(User::factory()->platformAdmin()->create());

    $this->postJson(route('api.payments.store', $payment->deal))->assertNotFound();
});

it('does not extend an existing selection window when quoting an older deal', function () {
    $deal = Deal::factory()->create(['created_at' => now()->subHours(25)]);
    $this->artisan('fees:quote-pending')->expectsOutput('Prepared 1 fee quote(s).')->assertSuccessful();

    expect($deal->fresh()->status)->toBe(DealStatus::Cancelled);
    expect($deal->fresh()->fee_deadline->isPast())->toBeTrue();
});

it('calculates the maximum supported principal fee with exact integer precision', function () {
    $deal = Deal::factory()->create(['terms' => ['amount' => '999999999999.99', 'currency' => 'GBP']]);
    app(QuotePlatformFee::class)->handle($deal);

    expect($deal->fresh()->fee_amount_minor)->toBe(100000000000);
    expect($deal->fresh()->fee_currency)->toBe('GBP');
});

it('rejects invalid fee policy configuration without persisting a partial quote', function () {
    $deal = Deal::factory()->create();
    config(['payments.fee_basis_points' => 0]);

    expect(fn () => app(QuotePlatformFee::class)->handle($deal))->toThrow(LogicException::class, 'Invalid demo fee policy.');
    expect($deal->fresh()->fee_amount_minor)->toBeNull();
});

it('disables new payment attempts when the demo provider is disabled with 404', function () {
    $approver = bankStaff(BankRole::Approver);
    $deal = paymentDeal($approver);
    config(['payments.demo_enabled' => false]);
    signInUser($approver);

    $this->postJson(route('api.payments.store', $deal))->assertNotFound();
    $this->assertDatabaseCount('fee_payments', 0);
});

it('delivers synthetic confirmation through the same signature validation boundary', function (bool $useReference) {
    $payment = feePaymentAttempt();

    $this->artisan('payments:simulate', ['payment' => $useReference ? $payment->provider_reference : $payment->id])->expectsOutput('Synthetic notification result: verified.')->assertSuccessful();
    expect($payment->deal->fresh()->hasVerifiedFee())->toBeTrue();
})->with(['payment ID' => false, 'visible payment reference' => true]);

it('sets up local demo payment keys while preserving unrelated environment settings', function () {
    $path = storage_path('framework/testing/demo-payment-env');
    File::ensureDirectoryExists($path);
    file_put_contents($path.'/.env', "MAIL_MAILER=mailpit\nMAIL_PORT=8025\nDEMO_PAYMENTS_ENABLED=false\n");
    app()->useEnvironmentPath($path);
    config(['payments.webhook_secret' => null]);
    try {
        $this->artisan('payments:setup-demo')->assertSuccessful();
        $content = file_get_contents($path.'/.env');
        expect($content)->toContain("MAIL_MAILER=mailpit\nMAIL_PORT=8025")->toContain('DEMO_PAYMENTS_ENABLED=true');
        expect((bool) preg_match('/^DEMO_PAYMENT_WEBHOOK_SECRET=[a-f0-9]{64}$/m', $content))->toBeTrue();
    } finally {
        File::deleteDirectory($path);
    }
});
