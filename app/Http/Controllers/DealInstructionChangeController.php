<?php

namespace App\Http\Controllers;

use App\Actions\ChangeDealInstructions;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Models\DealInstructionChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DealInstructionChangeController extends Controller
{
    public function store(Request $request, Deal $deal, ChangeDealInstructions $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('view', $deal);
        Gate::authorize('create', [DealInstructionChange::class, $deal]);
        $data = $request->validate(['settlement_instruction_id' => ['required', 'ulid'], 'status' => ['prohibited'], 'instruction_snapshot' => ['prohibited']]);
        $change = $action->propose($request->user(), $deal, $data['settlement_instruction_id']);

        return $request->expectsJson() || $request->is('api/*') ? response()->json(['data' => ['id' => $change->id, 'status' => $change->status]], 201) : redirect()->route('deals.show', $deal)->with('status', 'Instruction change proposed. Independent lender approval is required.');
    }

    public function review(Request $request, Deal $deal, DealInstructionChange $change, ChangeDealInstructions $action): RedirectResponse|DealResource
    {
        Gate::authorize('view', $deal);
        abort_unless($change->deal_id === $deal->id, 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:5', 'max:500']]);
        $record = $action->review($request->user(), $change, $data['decision'] === 'approve', $data['reason'] ?? null);

        return $request->expectsJson() || $request->is('api/*') ? new DealResource($record) : redirect()->route('deals.show', $deal)->with('status', 'Instruction review recorded. Financial terms and fee quote remain fixed.');
    }
}
