<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BankAuditController extends Controller
{
    public function __invoke(Request $request, Bank $bank): View|AnonymousResourceCollection
    {
        Gate::authorize('view', $bank);
        Gate::authorize('viewAudit', $bank);
        $records = AuditEvent::query()
            ->where('bank_id', $bank->id)
            ->with('actor.membership')
            ->latest('id')
            ->paginate(25);
        if ($request->expectsJson() || $request->is('api/*')) {
            return AuditEventResource::collection($records);
        }

        return view('banks.audit', [
            'bank' => $bank,
            'records' => $records,
            'rows' => AuditEventResource::collection($records)->resolve($request),
        ]);
    }
}
