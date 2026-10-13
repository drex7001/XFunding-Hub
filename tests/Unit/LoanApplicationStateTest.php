<?php

use App\States\LoanApplicationState;

dataset('state pairs', function () {
    $allowed = [
        'SUBMITTED' => ['UNDER_REVIEW', 'CANCELLED'],
        'UNDER_REVIEW' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['DISBURSED'],
    ];

    foreach (LoanApplicationState::classesByName() as $from => $fromClass) {
        foreach (LoanApplicationState::classesByName() as $to => $toClass) {
            yield "$from -> $to" => [$fromClass, $toClass, in_array($to, $allowed[$from] ?? [], true)];
        }
    }
});

it('allows exactly the specified transitions', function (string $from, string $to, bool $allowed) {
    // Arrange
    $configuration = LoanApplicationState::config();
    $source = $from::getMorphClass();
    $target = $to::getMorphClass();

    // Act
    $canTransition = $configuration->isTransitionAllowed($source, $target);

    // Assert
    expect($canTransition)->toBe($allowed);
})->with('state pairs');
