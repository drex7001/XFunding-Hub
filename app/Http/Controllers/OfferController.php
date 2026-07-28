<?php

namespace App\Http\Controllers;

use App\Actions\ManageOffers;
use App\Http\Requests\StoreOfferRequest;
use App\Http\Resources\OfferResource;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\SettlementInstruction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OfferController extends Controller
{
    public function index(Request $request): View|AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Offer::class);
        $records = Offer::query()->where('bank_id', $request->user()->membership->bank_id)
            ->with(['bank', 'fundingRequest.bank', 'fundingRequest.deal', 'settlementInstruction.bank', 'pendingApproval'])->latest()->paginate(15);
        if ($this->json($request)) {
            return OfferResource::collection($records);
        }

        return view('offers.index', ['records' => $records]);
    }

    public function create(Request $request, FundingRequest $fundingRequest): View
    {
        Gate::authorize('view', $fundingRequest);
        Gate::authorize('create', [Offer::class, $fundingRequest]);

        return $this->form($request, $fundingRequest);
    }

    public function store(StoreOfferRequest $request, FundingRequest $fundingRequest, ManageOffers $action): RedirectResponse|JsonResponse
    {
        $record = $action->create($request->user(), $fundingRequest, $request->validated());
        if ($this->json($request)) {
            return (new OfferResource($record))->response()->setStatusCode(201);
        }

        return redirect()->route('offers.show', $record)->with('status', 'Offer draft created. Submit it for independent lender approval.');
    }

    public function show(Request $request, Offer $offer): View|OfferResource
    {
        Gate::authorize('view', $offer);
        $offer->load(['bank', 'fundingRequest.bank', 'fundingRequest.deal', 'settlementInstruction.bank', 'pendingApproval']);
        if ($this->json($request)) {
            return new OfferResource($offer);
        }

        return view('offers.show', ['record' => $offer, 'data' => (new OfferResource($offer))->resolve($request), 'history' => OfferApproval::query()->where('offer_id', $offer->id)->latest()->limit(10)->get()]);
    }

    public function edit(Request $request, Offer $offer): View
    {
        Gate::authorize('view', $offer);
        Gate::authorize('update', $offer);

        return $this->form($request, $offer->fundingRequest, $offer);
    }

    public function update(StoreOfferRequest $request, Offer $offer, ManageOffers $action): RedirectResponse|OfferResource
    {
        return $this->respond($request, $action->update($request->user(), $offer, $request->validated()), 'Offer updated. Submit a fresh approval of the new terms.');
    }

    public function submit(Request $request, Offer $offer, ManageOffers $action): RedirectResponse|OfferResource
    {
        Gate::authorize('view', $offer);

        return $this->respond($request, $action->submit($request->user(), $offer), 'Offer submitted for independent approval.');
    }

    public function review(Request $request, Offer $offer, OfferApproval $approval, ManageOffers $action): RedirectResponse|OfferResource
    {
        Gate::authorize('view', $offer);
        abort_unless($approval->offer_id === $offer->id, 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:5', 'max:500']]);

        return $this->respond($request, $action->review($request->user(), $offer, $approval, $data['decision'] === 'approve', $data['reason'] ?? null), 'Offer review recorded.');
    }

    public function withdraw(Request $request, Offer $offer, ManageOffers $action): RedirectResponse|OfferResource
    {
        Gate::authorize('view', $offer);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->respond($request, $action->withdraw($request->user(), $offer, $data['reason']), 'Offer withdrawn. A replacement requires a new draft and approval.');
    }

    private function form(Request $request, FundingRequest $fundingRequest, ?Offer $offer = null): View
    {
        $instructions = SettlementInstruction::query()->where('bank_id', $request->user()->membership->bank_id)->where('status', 'approved')->with('bank')->latest('version')->get()->filter(fn (SettlementInstruction $record): bool => $record->matchesBankProfile());

        return view('offers.form', ['fundingRequest' => $fundingRequest, 'record' => $offer, 'instructions' => $instructions]);
    }

    private function respond(Request $request, Offer $offer, string $message): RedirectResponse|OfferResource
    {
        return $this->json($request) ? new OfferResource($offer) : redirect()->route('offers.show', $offer)->with('status', $message);
    }

    private function json(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }
}
