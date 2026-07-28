<?php

namespace App\Http\Controllers;

use App\Actions\ManageSettlementInstructions;
use App\Http\Requests\StoreSettlementInstructionRequest;
use App\Http\Resources\SettlementInstructionResource;
use App\Models\SettlementInstruction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettlementInstructionController extends Controller
{
    public function index(Request $request): View|AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SettlementInstruction::class);
        $records = SettlementInstruction::query()->where('bank_id', $request->user()->membership->bank_id)->latest('version')->paginate(15);
        if ($request->expectsJson() || $request->is('api/*')) {
            return SettlementInstructionResource::collection($records);
        }

        return view('instructions.index', ['records' => $records]);
    }

    public function store(StoreSettlementInstructionRequest $request, ManageSettlementInstructions $action): RedirectResponse|JsonResponse
    {
        $record = $action->create($request->user(), $request->validated());
        if ($request->expectsJson() || $request->is('api/*')) {
            return (new SettlementInstructionResource($record))->response()->setStatusCode(201);
        }

        return redirect()->route('instructions.show', $record)->with('status', 'Instruction version proposed. An independent lender approver must review it.');
    }

    public function show(Request $request, SettlementInstruction $instruction): View|SettlementInstructionResource
    {
        Gate::authorize('view', $instruction);
        if ($request->expectsJson() || $request->is('api/*')) {
            return new SettlementInstructionResource($instruction);
        }

        return view('instructions.show', ['record' => $instruction, 'data' => (new SettlementInstructionResource($instruction))->resolve($request)]);
    }

    public function review(Request $request, SettlementInstruction $instruction, ManageSettlementInstructions $action): RedirectResponse|SettlementInstructionResource
    {
        Gate::authorize('view', $instruction);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:5', 'max:500']]);
        $record = $action->review($request->user(), $instruction, $data['decision'] === 'approve', $data['reason'] ?? null);

        return $request->expectsJson() || $request->is('api/*') ? new SettlementInstructionResource($record) : back()->with('status', 'Instruction review recorded. Existing offers retain their approved version.');
    }
}
