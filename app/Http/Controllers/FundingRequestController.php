<?php

namespace App\Http\Controllers;

use App\Actions\ManageFundingRequests;
use App\Http\Requests\StoreFundingRequest;
use App\Http\Requests\UpdateFundingRequest;
use App\Http\Resources\FundingRequestResource;
use App\Models\FundingRequest;
use App\Models\FundingRequestApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FundingRequestController extends Controller
{
    public function index(Request $request): View|AnonymousResourceCollection
    {
        Gate::authorize('viewAny', FundingRequest::class);
        $records = FundingRequest::query()->where('bank_id', $request->user()->membership->bank_id)
            ->with(['bank', 'documents', 'pendingApproval', 'pendingSelection', 'deal'])->latest()->paginate(15);
        if ($this->json($request)) {
            return FundingRequestResource::collection($records);
        }

        return view('funding.index', ['records' => $records, 'marketplace' => false]);
    }

    public function marketplace(Request $request): View|AnonymousResourceCollection
    {
        Gate::authorize('viewMarketplace', FundingRequest::class);
        $records = FundingRequest::query()->published()->where('bank_id', '!=', $request->user()->membership->bank_id)
            ->with(['bank', 'documents', 'pendingApproval', 'pendingSelection', 'deal'])->latest('published_at')->paginate(15);
        if ($this->json($request)) {
            return FundingRequestResource::collection($records);
        }

        return view('funding.index', ['records' => $records, 'marketplace' => true]);
    }

    public function create(): View
    {
        Gate::authorize('create', FundingRequest::class);

        return view('funding.form', ['record' => null]);
    }

    public function store(StoreFundingRequest $request, ManageFundingRequests $action): RedirectResponse|JsonResponse
    {
        $record = $action->create($request->user(), $request->validated())->load(['bank', 'documents', 'pendingApproval', 'pendingSelection', 'deal']);
        if ($this->json($request)) {
            return (new FundingRequestResource($record))->response()->setStatusCode(201)->header('Location', route('api.funding.show', $record));
        }

        return redirect()->route('funding.show', $record)->with('status', 'Draft created. Attach financial documents before requesting publication.');
    }

    public function show(Request $request, FundingRequest $fundingRequest): View|FundingRequestResource
    {
        Gate::authorize('view', $fundingRequest);
        $fundingRequest->load(['bank', 'documents', 'pendingApproval', 'pendingSelection', 'deal']);
        if ($this->json($request)) {
            return new FundingRequestResource($fundingRequest);
        }

        return view('funding.show', [
            'record' => $fundingRequest,
            'data' => (new FundingRequestResource($fundingRequest))->resolve($request),
            'owned' => $request->user()->membership->bank_id === $fundingRequest->bank_id,
            'history' => $request->user()->membership->bank_id === $fundingRequest->bank_id ? $fundingRequest->approvals()->latest()->limit(10)->get() : collect(),
        ]);
    }

    public function edit(FundingRequest $fundingRequest): View
    {
        Gate::authorize('view', $fundingRequest);
        Gate::authorize('update', $fundingRequest);

        return view('funding.form', ['record' => $fundingRequest]);
    }

    public function update(UpdateFundingRequest $request, FundingRequest $fundingRequest, ManageFundingRequests $action): RedirectResponse|FundingRequestResource
    {
        return $this->respond($request, $action->update($request->user(), $fundingRequest, $request->validated()), 'Draft updated. Any previous publication proposal was superseded.');
    }

    public function submit(Request $request, FundingRequest $fundingRequest, ManageFundingRequests $action): RedirectResponse|FundingRequestResource
    {
        Gate::authorize('view', $fundingRequest);

        return $this->respond($request, $action->submit($request->user(), $fundingRequest), 'Submitted for independent publication approval.');
    }

    public function cancel(Request $request, FundingRequest $fundingRequest, ManageFundingRequests $action): RedirectResponse|FundingRequestResource
    {
        Gate::authorize('view', $fundingRequest);
        Gate::authorize('proposeCancellation', $fundingRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->respond($request, $action->proposeCancellation($request->user(), $fundingRequest, $data['reason']), 'Cancellation submitted for independent approval.');
    }

    public function review(Request $request, FundingRequest $fundingRequest, FundingRequestApproval $approval, ManageFundingRequests $action): RedirectResponse|FundingRequestResource
    {
        Gate::authorize('view', $fundingRequest);
        abort_unless($request->user()->membership?->bank_id === $fundingRequest->bank_id, 404);
        abort_unless($approval->funding_request_id === $fundingRequest->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:5', 'max:500'],
        ]);

        return $this->respond($request, $action->review($request->user(), $fundingRequest, $approval, $data['decision'] === 'approve', $data['reason'] ?? null), 'Request approval decision recorded.');
    }

    private function respond(Request $request, FundingRequest $record, string $message): RedirectResponse|FundingRequestResource
    {
        $record->load(['bank', 'documents', 'pendingApproval', 'pendingSelection', 'deal']);

        return $this->json($request) ? new FundingRequestResource($record) : redirect()->route('funding.show', $record)->with('status', $message);
    }

    private function json(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }
}
