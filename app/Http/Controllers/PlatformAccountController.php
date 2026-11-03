<?php

namespace App\Http\Controllers;

use App\Actions\ManagePlatformAccounts;
use App\Http\Requests\StorePlatformAccountRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PlatformAccountController extends Controller
{
    public function index(): View
    {
        Gate::authorize('managePlatformAccounts', User::class);

        return view('platform.accounts', [
            'records' => User::query()->where('is_platform_admin', true)->orderBy('name')->paginate(25),
        ]);
    }

    public function store(StorePlatformAccountRequest $request, ManagePlatformAccounts $action): RedirectResponse
    {
        $data = $request->validated();
        $action->create($request->user(), $data['name'], $data['email'], $data['reason']);

        return redirect()
            ->route('platform-accounts.index')
            ->with('status', 'Platform account created. A password setup link has been sent.');
    }

    public function deactivate(Request $request, User $account, ManagePlatformAccounts $action): RedirectResponse
    {
        Gate::authorize('changePlatformAccountStatus', $account);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $action->deactivate($request->user(), $account, $data['reason']);

        return redirect()
            ->route('platform-accounts.index')
            ->with('status', 'Platform account deactivated and its sessions revoked.');
    }

    public function reactivate(Request $request, User $account, ManagePlatformAccounts $action): RedirectResponse
    {
        Gate::authorize('changePlatformAccountStatus', $account);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $action->reactivate($request->user(), $account, $data['reason']);

        return redirect()->route('platform-accounts.index')->with('status', 'Platform account reactivated.');
    }
}
