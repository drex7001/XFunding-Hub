<?php

namespace App\Http\Controllers;

use App\Actions\ManageFeePayments;
use App\Actions\RecordAuditEvent;
use App\Http\Resources\PaymentReceiptResource;
use App\Models\PaymentNotification;
use App\Models\PaymentReceipt;
use App\PaymentReceiptStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PaymentOperationsController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', PaymentReceipt::class);

        return view('payments.operations', [
            'receipts' => PaymentReceipt::query()->where('status', PaymentReceiptStatus::RefundReview)->with('feePayment')->latest('received_at')->paginate(15),
            'notifications' => PaymentNotification::query()->whereIn('outcome', ['unmatched', 'charge_conflict'])->latest()->limit(20)->get(),
        ]);
    }

    public function review(Request $request, PaymentReceipt $receipt, RecordAuditEvent $audit): View|PaymentReceiptResource
    {
        Gate::authorize('view', $receipt);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $receipt->load('feePayment.deal.fundingRequest');
        $audit->handle($request->user(), 'fee.receipt_reviewed', $receipt->feePayment?->deal->fundingRequest ?? $receipt, ['receipt_id' => $receipt->id, 'reason' => $data['reason']]);
        if ($request->expectsJson()) {
            return new PaymentReceiptResource($receipt);
        }

        return view('payments.review', ['record' => $receipt, 'data' => (new PaymentReceiptResource($receipt))->resolve($request)]);
    }

    public function refund(Request $request, PaymentReceipt $receipt, ManageFeePayments $action): RedirectResponse|PaymentReceiptResource
    {
        Gate::authorize('refund', $receipt);
        $data = $request->validate(['refund_reference' => ['required', 'string', 'min:5', 'max:100'], 'reason' => ['required', 'string', 'min:8', 'max:500']]);
        $record = $action->recordRefund($request->user(), $receipt, $data['refund_reference'], $data['reason']);

        return $request->expectsJson() ? new PaymentReceiptResource($record) : redirect()->route('payments.operations')->with('status', 'Demo refund confirmation recorded. No bank transfer was executed.');
    }
}
