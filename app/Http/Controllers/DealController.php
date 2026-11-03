<?php

namespace App\Http\Controllers;

use App\Actions\DemoPaymentProvider;
use App\Actions\DescribeWorkflowProgress;
use App\Actions\RecordAuditEvent;
use App\FeePaymentStatus;
use App\Http\Resources\DealDisputeResource;
use App\Http\Resources\DealEventResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\SettlementConfirmationResource;
use App\Http\Resources\SettlementDisclosureResource;
use App\Models\Deal;
use App\Models\DealInstructionChange;
use App\Models\SettlementInstruction;
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
        $records = Deal::query()->where(fn (Builder $query): Builder => $query->where('borrower_bank_id', $bankId)->orWhere('lender_bank_id', $bankId))
            ->withWorkflowContext()->latest()->paginate(15);
        if ($request->expectsJson() || $request->is('api/*')) {
            return DealResource::collection($records);
        }

        return view('deals.index', ['records' => $records]);
    }

    public function show(Request $request, Deal $deal, DescribeWorkflowProgress $progress, DemoPaymentProvider $provider): View|DealResource
    {
        Gate::authorize('view', $deal);
        $deal = Deal::query()->whereKey($deal->id)->withWorkflowContext()->firstOrFail();
        if ($request->expectsJson() || $request->is('api/*')) {
            return new DealResource($deal);
        }
        $bankId = $request->user()->membership->bank_id;
        $side = $deal->sideOf($bankId);
        $instructions = collect();
        if (Gate::allows('create', [DealInstructionChange::class, $deal]) && $deal->acceptsInstructionChangeFrom($side)) {
            $instructions = SettlementInstruction::query()->where('bank_id', $bankId)->where('status', 'approved')
                ->when($deal->currentInstructionIdFor($side), fn (Builder $query, string $current): Builder => $query->whereKeyNot($current))
                ->with('bank')->latest('version')->get()->filter(fn (SettlementInstruction $record): bool => $record->matchesBankProfile());
        }

        $payments = $side === 'borrower' ? $deal->feePayments()->latest()->limit(20)->get() : collect();

        return view('deals.show', [
            'record' => $deal, 'data' => (new DealResource($deal))->resolve($request),
            'side' => $side, 'progress' => $progress->deal($deal),
            'payments' => $payments, 'pendingPayment' => $payments->firstWhere('status', FeePaymentStatus::Pending),
            'demoPaymentsEnabled' => $provider->enabled(),
            'instructions' => $instructions,
            'receivingInstructionsMissing' => $side === 'borrower' && $deal->borrower_settlement_instruction_id === null && $deal->acceptsInstructionChangeFrom($side),
            'confirmations' => SettlementConfirmationResource::collection($deal->confirmations()->where('bank_id', $request->user()->membership->bank_id)->latest()->limit(25)->get())->resolve($request),
            'disputes' => DealDisputeResource::collection($deal->disputes()->latest()->get())->resolve($request),
            'milestones' => DealEventResource::milestones($deal),
            'events' => $deal->events()->latest('id')->paginate(25),

        ]);
    }

    public function settlement(Request $request, Deal $deal, RecordAuditEvent $audit): View|SettlementDisclosureResource
    {
        Gate::authorize('view', $deal);
        $deal->load(['borrowerBank', 'lenderBank', 'settlementInstruction.bank', 'borrowerInstruction.bank', 'verifiedPayment.verifiedReceipt']);
        Gate::authorize('viewSettlement', $deal);
        $audit->handle($request->user(), 'deal.settlement_details_viewed', $deal->fundingRequest, ['deal_id' => $deal->id]);
        if ($request->expectsJson() || $request->is('api/*')) {
            return new SettlementDisclosureResource($deal);
        }

        return view('deals.settlement', ['record' => $deal, 'data' => (new SettlementDisclosureResource($deal))->resolve($request)]);
    }
}
