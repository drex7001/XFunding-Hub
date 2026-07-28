<?php

use App\Actions\ManageFeePayments;
use App\Actions\ManageSettlementConfirmations;
use App\BankRole;
use App\Models\Bank;
use App\Models\BankMembership;
use App\Models\Deal;
use App\Models\FeePayment;
use App\Models\FundingDocument;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\SettlementConfirmation;
use App\Models\SettlementInstruction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function bankStaff(BankRole $role = BankRole::Maker, ?Bank $bank = null): User
{
    $user = User::factory()->create();
    BankMembership::factory()->for($user)->for($bank ?? Bank::factory()->verified()->create())->create(['roles' => [$role->value]]);

    return $user->fresh(['membership.bank']);
}

function signInUser(User $user): void
{
    test()->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
}

/** @return array<string, mixed> */
function fundingPayload(array $overrides = []): array
{
    return array_replace([
        'title' => 'Short-term treasury liquidity', 'amount' => '250000000.00', 'currency' => 'LKR',
        'tenor_days' => 7, 'settlement_date' => now()->addDays(3)->toDateString(),
        'bidding_deadline' => now()->addDays(2)->toIso8601String(), 'day_count' => 'ACT/365F',
        'borrower_background' => 'Synthetic borrower financial history supplied for this funding request.',
    ], $overrides);
}

function fundingDraft(User $maker, array $attributes = []): FundingRequest
{
    return FundingRequest::factory()->create(array_replace([
        'bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id,
    ], $attributes));
}

function financialEvidence(FundingRequest $record): FundingDocument
{
    $document = FundingDocument::factory()->for($record)->create(['uploaded_by' => $record->created_by]);
    Storage::disk('private')->put($document->path, 'synthetic financial report');

    return $document;
}

/** @return array<string, mixed> */
function instructionPayload(array $overrides = []): array
{
    return array_replace([
        'channel' => 'swift', 'account_name' => 'Synthetic Treasury Account', 'account_number' => 'DEMO000098765432',
        'bic' => 'DEMOLKXX', 'contact_name' => 'Synthetic Treasury Contact', 'contact_email' => 'desk@synthetic.example',
    ], $overrides);
}

/** @return array<string, mixed> */
function offerPayload(SettlementInstruction $instruction, FundingRequest $request, array $overrides = []): array
{
    return array_replace([
        'rate' => '8.1250', 'collateral' => 'unsecured', 'interest_payment' => 'at_maturity',
        'valid_until' => $request->settlement_date->endOfDay()->toIso8601String(), 'settlement_instruction_id' => $instruction->id,
    ], $overrides);
}

function marketOffer(User $maker, FundingRequest $request, array $overrides = []): Offer
{
    return Offer::factory()->active()->create(array_replace([
        'bank_id' => $maker->membership->bank_id, 'created_by' => $maker->id, 'funding_request_id' => $request->id,
    ], $overrides));
}

function enableDemoPayments(): void
{
    config(['payments.demo_enabled' => true, 'payments.driver' => 'demo', 'payments.webhook_secret' => str_repeat('test-webhook-key-', 4)]);
}

function paymentDeal(User $borrower): Deal
{
    $request = FundingRequest::factory()->published()->create(['bank_id' => $borrower->membership->bank_id]);
    $offer = marketOffer(bankStaff(), $request);

    return Deal::factory()->quoted()->create(['offer_id' => $offer->id]);
}

/** @return array<string, mixed> */
function paymentNotificationPayload(FeePayment $payment, array $overrides = []): array
{
    return array_replace([
        'event_reference' => (string) Str::uuid(), 'payment_reference' => $payment->provider_reference,
        'deal_id' => $payment->deal_id, 'outcome' => 'paid', 'amount' => $payment->amount,
        'currency' => $payment->currency, 'charge_reference' => (string) Str::uuid(),
    ], $overrides);
}

function signedPaymentNotification(array $payload, array $headers = []): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->getTimestamp();
    $server = array_replace([
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYMENT_TIMESTAMP' => $timestamp,
        'HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, config('payments.webhook_secret')),
    ], $headers);

    return test()->call('POST', route('payments.demo.notify'), [], [], [], $server, $body);
}

function feePaymentAttempt(): FeePayment
{
    $approver = bankStaff(BankRole::Approver);

    return app(ManageFeePayments::class)->initiate($approver, paymentDeal($approver));
}

function unlockedDeal(): Deal
{
    $payment = feePaymentAttempt();
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk();

    return $payment->deal->fresh();
}

/** @return array{settlement_at: string, reference: string, note: string} */
function settlementPayload(): array
{
    return ['settlement_at' => now()->toIso8601String(), 'reference' => 'SYNTHETIC-OFFPLATFORM-REF', 'note' => 'Private institution evidence summary.'];
}

function confirmedInstitution(Deal $deal, Bank $bank): SettlementConfirmation
{
    $maker = bankStaff(BankRole::Maker, $bank);
    $record = app(ManageSettlementConfirmations::class)->propose($maker, $deal, settlementPayload());
    app(ManageSettlementConfirmations::class)->review(bankStaff(BankRole::Approver, $bank), $record, true, null);

    return $record->fresh();
}
