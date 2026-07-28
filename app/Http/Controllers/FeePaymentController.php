<?php

namespace App\Http\Controllers;

use App\Actions\ManageFeePayments;
use App\Http\Resources\FeePaymentResource;
use App\Models\Deal;
use App\Models\FeePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeePaymentController extends Controller
{
    public function store(Request $request, Deal $deal, ManageFeePayments $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('view', $deal);
        Gate::authorize('create', [FeePayment::class, $deal]);
        $request->validate(array_fill_keys(['amount', 'currency', 'deal_id', 'status', 'paid', 'receipt', 'provider_reference', 'verified_payment_id', 'fee_amount_minor', 'lender_bank_id', 'provider'], ['prohibited']));
        $payment = $action->initiate($request->user(), $deal);
        if ($request->expectsJson() || $request->is('api/*')) {
            return (new FeePaymentResource($payment))->response()->setStatusCode($payment->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('deals.show', $deal)->with('status', 'Demo payment requested. Awaiting independent provider confirmation.');
    }

    public function show(FeePayment $feePayment): FeePaymentResource
    {
        Gate::authorize('view', $feePayment);

        return new FeePaymentResource($feePayment);
    }
}
