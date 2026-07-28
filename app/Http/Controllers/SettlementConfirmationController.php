<?php

namespace App\Http\Controllers;

use App\Actions\ManageSettlementConfirmations;
use App\Http\Requests\ReviewSettlementConfirmationRequest;
use App\Http\Requests\StoreSettlementConfirmationRequest;
use App\Http\Resources\DealResource;
use App\Http\Resources\SettlementConfirmationResource;
use App\Models\Deal;
use App\Models\SettlementConfirmation;
use Illuminate\Http\RedirectResponse;

class SettlementConfirmationController extends Controller
{
    public function store(StoreSettlementConfirmationRequest $request, Deal $deal, ManageSettlementConfirmations $action): RedirectResponse|SettlementConfirmationResource
    {
        $record = $action->propose($request->user(), $deal, $request->validated());

        return $request->expectsJson() || $request->is('api/*') ? new SettlementConfirmationResource($record) : redirect()->route('deals.show', $deal)->with('status', 'Settlement attestation proposed. A different treasury approver must review it.');
    }

    public function review(ReviewSettlementConfirmationRequest $request, Deal $deal, SettlementConfirmation $confirmation, ManageSettlementConfirmations $action): RedirectResponse|DealResource
    {
        $data = $request->validated();
        $record = $action->review($request->user(), $confirmation, $data['decision'] === 'approve', $data['reason'] ?? null);

        return $request->expectsJson() || $request->is('api/*') ? new DealResource($record) : redirect()->route('deals.show', $deal)->with('status', 'Settlement review recorded. Both institutions must independently confirm.');
    }
}
