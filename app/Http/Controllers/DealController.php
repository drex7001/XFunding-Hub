<?php

namespace App\Http\Controllers;

use App\Actions\RecordAuditEvent;
use App\DealStatus;
use App\FeePaymentStatus;
use App\Http\Resources\DealDisputeResource;
use App\Http\Resources\DealEventResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\SettlementConfirmationResource;
use App\Http\Resources\SettlementDisclosureResource;
use App\Models\Deal;
use App\Models\DealInstructionChange;
use App\Models\SettlementInstruction;
use App\PaymentReceiptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DealController extends Controller
{
    public function index(Request $request): View|AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Deal::class);
        $bankId = $request->user()->membership->bank_id;
        $records = $this->withPaymentContext(Deal::query()->where(fn (Builder $query): Builder => $query->where('borrower_bank_id', $bankId)->orWhere('lender_bank_id', $bankId)))
            ->latest()->paginate(15);
        if ($request->expectsJson() || $request->is('api/*')) {
            return DealResource::collection($records);
        }

        return view('deals.index', ['records' => $records]);
    }

    public function show(Request $request, Deal $deal): View|DealResource
    {
        Gate::authorize('view', $deal);
        $deal = $this->withPaymentContext(Deal::query()->whereKey($deal->id))->firstOrFail();
        if ($request->expectsJson() || $request->is('api/*')) {
            return new DealResource($deal);
        }
        $instructions = collect();
        if (Gate::allows('create', [DealInstructionChange::class, $deal]) && in_array($deal->currentStatus(), [DealStatus::AwaitingFee, DealStatus::Disputed], true)) {
            $instructions = SettlementInstruction::query()->where('bank_id', $deal->lender_bank_id)->where('status', 'approved')->where('id', '!=', $deal->settlement_instruction_id)->with('bank')->latest('version')->get()->filter(fn (SettlementInstruction $record): bool => $record->matchesBankProfile());
        }

        return view('deals.show', [
            'record' => $deal, 'data' => (new DealResource($deal))->resolve($request),
            'payments' => $request->user()->membership->bank_id === $deal->borrower_bank_id ? $deal->feePayments()->latest()->limit(20)->get() : collect(),
            'instructions' => $instructions,
            'confirmations' => SettlementConfirmationResource::collection($deal->confirmations()->where('bank_id', $request->user()->membership->bank_id)->latest()->limit(25)->get())->resolve($request),
            'disputes' => DealDisputeResource::collection($deal->disputes()->latest()->get())->resolve($request),
            'milestones' => DealEventResource::milestones($deal),
            'events' => $deal->events()->latest('id')->paginate(25),

        ]);
    }

    public function settlement(Request $request, Deal $deal, RecordAuditEvent $audit): View|SettlementDisclosureResource
    {
        Gate::authorize('view', $deal);
        $deal->load(['borrowerBank', 'lenderBank', 'settlementInstruction.bank', 'verifiedPayment.verifiedReceipt']);
        Gate::authorize('viewSettlement', $deal);
        $audit->handle($request->user(), 'deal.settlement_details_viewed', $deal->fundingRequest, ['deal_id' => $deal->id]);
        if ($request->expectsJson() || $request->is('api/*')) {
            return new SettlementDisclosureResource($deal);
        }

        return view('deals.settlement', ['record' => $deal, 'data' => (new SettlementDisclosureResource($deal))->resolve($request)]);
    }

    private function withPaymentContext(Builder $query): Builder
    {
        return $query->with(['borrowerBank', 'lenderBank', 'settlementInstruction.bank', 'verifiedPayment.verifiedReceipt', 'pendingInstructionChange.settlementInstruction', 'pendingInstructionChange.deal', 'fundingRequest', 'activeDispute', 'confirmations'])
            ->withCount([
                'feePayments as pending_payments_count' => fn (Builder $payments): Builder => $payments->where('status', FeePaymentStatus::Pending),
                'receipts as pending_refunds_count' => fn (Builder $receipts): Builder => $receipts->where('payment_receipts.status', PaymentReceiptStatus::RefundReview),
            ]);
    }
}
