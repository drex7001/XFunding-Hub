<?php

namespace App\Http\Controllers;

use App\Actions\ManageBankAccess;
use App\Http\Requests\StoreAccessRequest;
use App\Models\AccessRequest;
use App\Models\BankMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccessRequestController extends Controller
{
    public function store(StoreAccessRequest $request, ManageBankAccess $action): RedirectResponse
    {
        $action->propose($request->user(), $request->user()->membership->bank, $request->validated());

        return back()->with('status', 'Invitation submitted for independent approval.');
    }

    public function roles(StoreAccessRequest $request, BankMembership $membership, ManageBankAccess $action): RedirectResponse
    {
        $action->propose($request->user(), $request->user()->membership->bank, $request->validated(), $membership);

        return back()->with('status', 'Role change submitted for independent approval.');
    }

    public function review(Request $request, AccessRequest $accessRequest, ManageBankAccess $action): RedirectResponse
    {
        Gate::authorize('view', $accessRequest);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $action->review($request->user(), $accessRequest, $data['decision'] === 'approve');

        return back()->with('status', 'Access request '.($data['decision'] === 'approve' ? 'approved' : 'rejected').'.');
    }

    public function revoke(Request $request, BankMembership $membership, ManageBankAccess $action): RedirectResponse
    {
        Gate::authorize('view', $membership->bank);
        $action->revoke($request->user(), $membership);

        return back()->with('status', 'Membership revoked. Existing sessions no longer have access.');
    }
}
