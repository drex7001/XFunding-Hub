<?php

namespace App\Http\Controllers;

use App\Actions\ManageDealDisputes;
use App\Actions\RecordAuditEvent;
use App\Http\Requests\ResolveDealDisputeRequest;
use App\Http\Requests\StoreDealDisputeRequest;
use App\Http\Resources\DealDisputeResource;
use App\Models\Deal;
use App\Models\DealDispute;
use App\Models\SettlementConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DealDisputeController extends Controller
{
    public function store(StoreDealDisputeRequest $request, Deal $deal, ManageDealDisputes $action): RedirectResponse|DealDisputeResource
    {
        $record = $action->open($request->user(), $deal, $request->validated('description'));

        return $request->expectsJson() || $request->is('api/*') ? new DealDisputeResource($record) : redirect()->route('deals.show', $deal)->with('status', 'Dispute opened. Settlement requires an audited operations resolution.');
    }

    public function index(): View
    {
        Gate::authorize('viewAny', DealDispute::class);

        return view('deals.operations', ['records' => DealDispute::query()->latest()->paginate(15)]);
    }

    public function review(Request $request, DealDispute $dispute, RecordAuditEvent $audit): View|JsonResponse
    {
        Gate::authorize('view', $dispute);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $deal = $dispute->deal->load(['confirmations', 'borrowerBank', 'lenderBank', 'settlementInstruction.bank', 'verifiedPayment.verifiedReceipt', 'pendingInstructionChange', 'fundingRequest']);
        $audit->handle($request->user(), 'deal.dispute_reviewed', $deal, ['dispute_id' => $dispute->id, 'reason' => $data['reason']]);
        $details = ['id' => $dispute->id, 'dealId' => $deal->id, 'status' => $dispute->status,
            'description' => $dispute->description, 'resolutionNote' => $dispute->resolution_note,
            'canSettle' => $dispute->status === 'open' && $deal->settlementReady() && $deal->hasBothConfirmations(),
            'confirmations' => $deal->confirmations->map(fn (SettlementConfirmation $confirmation): array => [
                'side' => $confirmation->bank_id === $deal->borrower_bank_id ? 'borrower' : 'lender', 'status' => $confirmation->status,
                'reference' => $confirmation->reference, 'note' => $confirmation->note, 'settlementAt' => $confirmation->settlement_at->toIso8601String(),
                'reviewedAt' => $confirmation->reviewed_at?->toIso8601String(), 'currentVersion' => $confirmation->settlement_instruction_id === $deal->settlement_instruction_id,
            ])->all()];

        return $request->expectsJson() ? response()->json(['data' => $details]) : view('deals.dispute-review', ['record' => $dispute, 'data' => $details]);
    }

    public function resolve(ResolveDealDisputeRequest $request, DealDispute $dispute, ManageDealDisputes $action): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $deal = $action->resolve($request->user(), $dispute, $data['outcome'], $data['reason']);

        return $request->expectsJson() ? response()->json(['data' => ['id' => $deal->id, 'status' => $deal->status->value]]) : redirect()->route('disputes.operations')->with('status', 'Dispute resolution recorded. No interbank transfer was executed by the platform.');
    }
}
