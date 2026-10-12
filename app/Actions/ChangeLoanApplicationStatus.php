<?php

namespace App\Actions;

use App\Models\LoanApplication;
use App\States\LoanApplicationState;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ChangeLoanApplicationStatus
{
    public function handle(LoanApplication $application, string $status): LoanApplication
    {
        return DB::transaction(function () use ($application, $status) {
            $current = LoanApplication::query()->lockForUpdate()->findOrFail($application->id);

            try {
                $current->status->transitionTo(LoanApplicationState::classesByName()[$status]);
            } catch (TransitionNotFound $exception) {
                throw new ConflictHttpException(
                    "Cannot transition from {$current->status} to {$status}.",
                    $exception,
                );
            }

            return $current;
        }, attempts: 3);
    }
}
