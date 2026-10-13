<?php

use App\States\LoanApplicationState;

dataset('state pairs', function () {
    $allowed = [
        'SUBMITTED' => ['UNDER_REVIEW'],
        'UNDER_REVIEW' => ['APPROVED', 'REJECTED'],
        'APPROVED' => ['DISBURSED'],
    ];

    foreach (LoanApplicationState::classesByName() as $from => $fromClass) {
        foreach (LoanApplicationState::classesByName() as $to => $toClass) {
            yield "$from -> $to" => [$fromClass, $toClass, in_array($to, $allowed[$from] ?? [], true)];
        }
    }
});

it('allows exactly the four specified transitions', function (string $from, string $to, bool $allowed) {
    // No database or Laravel application is needed to inspect the state configuration.
    expect(LoanApplicationState::config()->isTransitionAllowed(
        $from::getMorphClass(),
        $to::getMorphClass(),
    ))->toBe($allowed);
})->with('state pairs');
