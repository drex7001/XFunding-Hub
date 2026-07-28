<?php

namespace App\Http\Controllers;

use App\Actions\SelectOffer;
use App\FundingRequestStatus;
use App\Http\Resources\AnonymousOfferResource;
use App\Http\Resources\DealResource;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\SelectionProposal;
use App\OfferStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SelectionController extends Controller
{
    public function index(Request $request, FundingRequest $fundingRequest): View|JsonResponse
    {
        Gate::authorize('viewAny', [SelectionProposal::class, $fundingRequest]);
        $records = $fundingRequest->offers()->where('status', OfferStatus::Active)->where('valid_until', '>', now())
            ->with(['bank', 'settlementInstruction.bank'])->orderBy('label_number')->get()
            ->filter(fn (Offer $offer): bool => $offer->isSelectable());
        $pending = $fundingRequest->pendingSelection?->load('offer');
        $data = [
            'offers' => AnonymousOfferResource::collection($records->values())->resolve($request),
            'pendingSelection' => $pending ? ['id' => $pending->id, 'offer' => (new AnonymousOfferResource($pending->offer))->resolve($request), 'canReview' => Gate::allows('review', $pending)] : null,
            'deal' => $fundingRequest->deal ? (new DealResource($fundingRequest->deal))->resolve($request) : null,
            'canPropose' => Gate::allows('create', [SelectionProposal::class, $fundingRequest]) && $fundingRequest->currentStatus() === FundingRequestStatus::Open && $fundingRequest->pendingApproval === null && $pending === null && $fundingRequest->deal === null,
        ];
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['data' => $data]);
        }

        return view('offers.compare', ['record' => $fundingRequest, 'data' => $data]);
    }

    public function store(Request $request, FundingRequest $fundingRequest, SelectOffer $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', [SelectionProposal::class, $fundingRequest]);
        Gate::authorize('create', [SelectionProposal::class, $fundingRequest]);
        $data = $request->validate(['offer_token' => ['required', 'uuid'], 'offer_id' => ['prohibited'], 'lender_bank_id' => ['prohibited'], 'status' => ['prohibited']]);
        $proposal = $action->propose($request->user(), $fundingRequest, $data['offer_token']);

        return $request->expectsJson() || $request->is('api/*') ? response()->json(['data' => ['id' => $proposal->id, 'status' => 'pending']], 201) : redirect()->route('funding.offers', $fundingRequest)->with('status', 'Selection proposed. An independent borrower approver must review it.');
    }

    public function review(Request $request, FundingRequest $fundingRequest, SelectionProposal $selection, SelectOffer $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', [SelectionProposal::class, $fundingRequest]);
        abort_unless($selection->funding_request_id === $fundingRequest->id, 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:5', 'max:500']]);
        $deal = $action->review($request->user(), $selection, $data['decision'] === 'approve', $data['reason'] ?? null);
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['data' => $deal ? (new DealResource($deal))->resolve($request) : ['status' => 'rejected']]);
        }

        return $deal ? redirect()->route('deals.show', $deal)->with('status', 'Selection approved. Lender identity remains locked pending verified platform fee.') : redirect()->route('funding.offers', $fundingRequest)->with('status', 'Selection rejected. A maker can propose another offer.');
    }
}
