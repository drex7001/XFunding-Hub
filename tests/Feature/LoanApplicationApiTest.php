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

function arrangeLoan(string $status = 'SUBMITTED'): LoanApplication
{
    $application = new LoanApplication([
        'customer_id' => 'C1001',
        'amount' => 5000,
        'currency' => 'GBP',
        'term_months' => 12,
    ]);
    $application->status = $status;
    $application->save();

    return $application;
}

it('creates and persists an application using the specified response shape', function () {
    // Arrange
    $payload = loanPayload();

    // Act
    $response = $this->postJson('/api/v1/loan-applications', $payload);

    // Assert
    $response->assertCreated()
        ->assertHeader('Location', url('/api/v1/loan-applications/LA1001'))
        ->assertExactJson([
            'applicationId' => 'LA1001',
            ...$payload,
            'status' => 'SUBMITTED',
        ]);

    $this->assertDatabaseHas('loan_applications', [
        'customer_id' => 'C1001', 'amount' => 5000, 'status' => 'SUBMITTED',
    ]);

});

it('retrieves an application using the specified response shape', function () {
    // Arrange
    $application = arrangeLoan();
    $id = $application->getRouteKey();

    // Act
    $response = $this->getJson("/api/v1/loan-applications/$id");

    // Assert
    $response->assertOk()->assertExactJson([
        'applicationId' => $id,
        ...loanPayload(),
        'status' => 'SUBMITTED',
    ]);
});

it('completes the approval and disbursement workflow', function () {
    // Arrange
    $application = arrangeLoan();
    $id = $application->getRouteKey();
    $statuses = ['UNDER_REVIEW', 'APPROVED', 'DISBURSED'];
    $responses = [];
    $persistedStatuses = [];

    // Act: exercise the complete sequence, capturing each persisted state.
    foreach ($statuses as $status) {
        $responses[$status] = $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => $status]);
        $persistedStatuses[] = (string) $application->refresh()->status;
    }

    // Assert
    foreach ($responses as $status => $response) {
        $response->assertOk()->assertJsonPath('status', $status);
    }
    expect($persistedStatuses)->toBe($statuses);
});

it('supports rejection after review', function () {
    // Arrange
    $application = arrangeLoan('UNDER_REVIEW');
    $id = $application->getRouteKey();

    // Act
    $response = $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => 'REJECTED']);

    // Assert
    $response->assertOk()->assertJsonPath('status', 'REJECTED');
    expect((string) $application->refresh()->status)->toBe('REJECTED');
});

it('allows cancellation before approval', function (string $from) {
    // Arrange
    $application = arrangeLoan($from);
    $id = $application->getRouteKey();

    // Act
    $response = $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => 'CANCELLED']);

    // Assert
    $response->assertOk()->assertJsonPath('status', 'CANCELLED');
    expect((string) $application->refresh()->status)->toBe('CANCELLED');
})->with(['SUBMITTED', 'UNDER_REVIEW']);

it('rejects every invalid transition without changing persisted state', function (string $from, string $to) {
    // Arrange
    $application = arrangeLoan($from);
    $id = $application->getRouteKey();

    // Act
    $response = $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => $to]);

    // Assert
    $response
        ->assertConflict()->assertJsonPath('message', "Cannot transition from $from to $to.");

    expect((string) $application->refresh()->status)->toBe($from);
})->with(function () {
    $allowed = [
        'SUBMITTED:UNDER_REVIEW',
        'SUBMITTED:CANCELLED',
        'UNDER_REVIEW:APPROVED',
        'UNDER_REVIEW:REJECTED',
        'UNDER_REVIEW:CANCELLED',
        'APPROVED:DISBURSED',
    ];
    $states = ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'DISBURSED', 'CANCELLED'];
    foreach ($states as $from) {
        foreach ($states as $to) {
            if (! in_array("$from:$to", $allowed, true)) {
                yield "$from -> $to" => [$from, $to];
            }
        }
    }
});

it('validates create inputs and does not persist invalid requests', function (array $payload, string $field) {
    // Arrange: the dataset supplies the invalid payload and expected field.

    // Act
    $response = $this->postJson('/api/v1/loan-applications', $payload);

    // Assert
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
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
    // Arrange
    $application = arrangeLoan();
    $id = $application->getRouteKey();

    // Act
    $response = $this->patchJson("/api/v1/loan-applications/$id/status", ['status' => $status]);

    // Assert
    $response->assertUnprocessable()->assertJsonValidationErrors('status');
    expect((string) $application->refresh()->status)->toBe('SUBMITTED');
})->with([null, 'UNKNOWN', 'approved', 123]);

it('returns JSON 404 for unknown application IDs', function (string $id) {
    // Arrange: the database is empty and the dataset supplies an unknown ID.
    $url = "/api/v1/loan-applications/$id";

    // Act
    $getResponse = $this->getJson($url);
    $patchResponse = $this->patchJson("$url/status", ['status' => 'UNDER_REVIEW']);

    // Assert
    $getResponse->assertNotFound();
    $patchResponse->assertNotFound();
})->with(['LA999999', 'invalid', 'LA0001']);

it('generates distinct application IDs', function () {
    // Arrange
    $payload = loanPayload();

    // Act
    $firstResponse = $this->postJson('/api/v1/loan-applications', $payload);
    $secondResponse = $this->postJson('/api/v1/loan-applications', $payload);

    // Assert
    $firstResponse->assertCreated();
    $secondResponse->assertCreated();
    expect($firstResponse->json('applicationId'))
        ->toBeString()
        ->not->toBe($secondResponse->json('applicationId'));
    $this->assertDatabaseCount('loan_applications', 2);
});
