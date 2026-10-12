<?php

namespace App\Http\Controllers;

use App\Actions\ChangeLoanApplicationStatus;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Http\Requests\UpdateLoanApplicationStatusRequest;
use App\Http\Resources\LoanApplicationResource;
use App\Models\LoanApplication;
use Illuminate\Http\JsonResponse;

class LoanApplicationController extends Controller
{
    public function store(StoreLoanApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $application = LoanApplication::create([
            'customer_id' => $data['customerId'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'term_months' => $data['termMonths'],
        ]);

        return (new LoanApplicationResource($application))->response()
            ->setStatusCode(201)
            ->header('Location', route('loan-applications.show', $application));
    }

    public function show(LoanApplication $application): LoanApplicationResource
    {
        return new LoanApplicationResource($application);
    }

    public function updateStatus(
        UpdateLoanApplicationStatusRequest $request,
        LoanApplication $application,
        ChangeLoanApplicationStatus $action,
    ): LoanApplicationResource {
        return new LoanApplicationResource($action->handle($application, $request->validated('status')));
    }
}
