<?php

use App\Models\LoanApplication;

function loanPayload(array $overrides = []): array
{
    return array_replace([
        'customerId' => 'C1001',
        'amount' => 5000.00,
        'currency' => 'GBP',
        'termMonths' => 12,
    ], $overrides);
}

function submitLoan(): string
{
    return test()->postJson('/api/v1/loan-applications', loanPayload())
        ->assertCreated()->json('applicationId');
}

it('creates, persists and retrieves an application using the specified response shape', function () {
    $response = $this->postJson('/api/v1/loan-applications', loanPayload());

    $response->assertCreated()
        ->assertHeader('Location', url('/api/v1/loan-applications/LA1001'))
        ->assertExactJson([
            'applicationId' => 'LA1001',
            ...loanPayload(),
            'status' => 'SUBMITTED',
        ]);

    $this->assertDatabaseHas('loan_applications', [
        'customer_id' => 'C1001', 'amount' => 5000, 'status' => 'SUBMITTED',
    ]);

    $this->getJson('/api/v1/loan-applications/LA1001')
        ->assertOk()->assertExactJson($response->json());
});

it('completes the approval and disbursement workflow', function () {
    $id = submitLoan();

    foreach (['UNDER_REVIEW', 'APPROVED', 'DISBURSED'] as $status) {
        $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => $status])
            ->assertOk()->assertJsonPath('status', $status);
        $this->assertDatabaseHas('loan_applications', ['status' => $status]);
    }
});

it('supports rejection after review', function () {
    $id = submitLoan();
    $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => 'UNDER_REVIEW'])->assertOk();
    $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => 'REJECTED'])
        ->assertOk()->assertJsonPath('status', 'REJECTED');
});

it('rejects every invalid transition without changing persisted state', function (string $from, string $to) {
    $id = submitLoan();
    // Arrange each source directly; actual transition behavior is exercised through HTTP.
    LoanApplication::query()->update(['status' => $from]);

    $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => $to])
        ->assertConflict()->assertJsonPath('message', "Cannot transition from $from to $to.");

    $this->getJson("/api/v1/loan-applications/$id")->assertOk()->assertJsonPath('status', $from);
})->with(function () {
    $allowed = ['SUBMITTED:UNDER_REVIEW', 'UNDER_REVIEW:APPROVED', 'UNDER_REVIEW:REJECTED', 'APPROVED:DISBURSED'];
    $states = ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'DISBURSED'];
    foreach ($states as $from) {
        foreach ($states as $to) {
            if (! in_array("$from:$to", $allowed, true)) {
                yield "$from -> $to" => [$from, $to];
            }
        }
    }
});

it('validates create inputs and does not persist invalid requests', function (array $payload, string $field) {
    $this->postJson('/api/v1/loan-applications', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('loan_applications', 0);
})->with([
    'missing customer' => [loanPayload(['customerId' => null]), 'customerId'],
    'blank customer' => [loanPayload(['customerId' => '   ']), 'customerId'],
    'zero amount' => [loanPayload(['amount' => 0]), 'amount'],
    'negative amount' => [loanPayload(['amount' => -1]), 'amount'],
    'excess precision' => [loanPayload(['amount' => 1.234]), 'amount'],
    'amount overflow' => [loanPayload(['amount' => 10000000000]), 'amount'],
    'lowercase currency' => [loanPayload(['currency' => 'gbp']), 'currency'],
    'short currency' => [loanPayload(['currency' => 'GB']), 'currency'],
    'zero term' => [loanPayload(['termMonths' => 0]), 'termMonths'],
    'fractional term' => [loanPayload(['termMonths' => 1.5]), 'termMonths'],
    'client status' => [loanPayload(['status' => 'APPROVED']), 'status'],
    'client ID' => [loanPayload(['applicationId' => 'LA9999']), 'applicationId'],
]);

it('validates status inputs', function (mixed $status) {
    $id = submitLoan();
    $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => $status])
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->assertDatabaseHas('loan_applications', ['status' => 'SUBMITTED']);
})->with([null, 'UNKNOWN', 'approved', 123]);

it('returns JSON 404 for unknown application IDs', function (string $id) {
    $this->getJson("/api/v1/loan-applications/$id")->assertNotFound();
    $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => 'UNDER_REVIEW'])->assertNotFound();
})->with(['LA999999', 'invalid', 'LA0001']);

it('generates distinct application IDs', function () {
    expect(submitLoan())->not->toBe(submitLoan());
});
