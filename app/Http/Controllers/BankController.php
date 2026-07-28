<?php

namespace App\Http\Controllers;

use App\Actions\ManageBankAccess;
use App\Actions\RecordAuditEvent;
use App\BankRole;
use App\BankStatus;
use App\Http\Requests\StoreBankRequest;
use App\Http\Requests\UpdateBankRequest;
use App\Models\AccessRequest;
use App\Models\AuditEvent;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BankController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('create', Bank::class);

        return view('operations', [
            'banks' => Bank::query()->latest()->paginate(15),
            'accessRequests' => AccessRequest::query()->where('status', 'pending')->with(['bank', 'membership'])->latest()->limit(30)->get(),
        ]);
    }

    public function show(Request $request, Bank $bank): View
    {
        Gate::authorize('view', $bank);
        $manage = $request->user()->can('manageMembers', $bank) || $request->user()->is_platform_admin;

        return view('bank', [
            'bank' => $bank->load('documents'), 'roles' => BankRole::cases(),
            'memberships' => $manage ? $bank->memberships()->with('user')->get() : collect(),
            'accessRequests' => $manage ? $bank->accessRequests()->with(['membership', 'bank'])->latest()->limit(30)->get() : collect(),
            'events' => AuditEvent::query()->where('bank_id', $bank->id)->latest()->limit(12)->get(),
        ]);
    }

    public function store(StoreBankRequest $request, ManageBankAccess $action): RedirectResponse
    {
        $bank = $action->createBank($request->user(), $request->validated());

        return redirect()->route('banks.show', $bank)->with('status', 'Bank created. The first bank admin invitation is queued for delivery.');
    }

    public function update(UpdateBankRequest $request, Bank $bank, RecordAuditEvent $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $bank, $audit): void {
            $bank = Bank::query()->lockForUpdate()->findOrFail($bank->id);
            Gate::authorize('update', $bank);
            $bank->fill($request->validated())->forceFill(['status' => BankStatus::Pending, 'can_borrow' => false, 'can_lend' => false, 'verified_at' => null])->save();
            $audit->handle($request->user(), 'bank.profile_updated', $bank);
        });

        return back()->with('status', 'Profile saved. Platform verification is required before marketplace access.');
    }

    public function verify(Request $request, Bank $bank, RecordAuditEvent $audit): RedirectResponse
    {
        Gate::authorize('verify', $bank);
        $data = $request->validate([
            'capabilities' => ['required', 'array', 'min:1', 'max:2'],
            'capabilities.*' => ['required', Rule::in($bank->requested_capabilities), 'distinct'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $bank, $audit, $data): void {
            $bank = Bank::query()->lockForUpdate()->findOrFail($bank->id);
            abort_unless($bank->status === BankStatus::Pending, 409, 'Only a pending bank can be verified.');
            abort_unless(array_diff($data['capabilities'], $bank->requested_capabilities) === [], 409, 'Requested capabilities changed. Review the profile again.');
            $bank->forceFill(['status' => BankStatus::Verified, 'can_borrow' => in_array('borrower', $data['capabilities'], true), 'can_lend' => in_array('lender', $data['capabilities'], true), 'verified_at' => now()])->save();
            $audit->handle($request->user(), 'bank.verified', $bank, $data);
        });

        return back()->with('status', 'Bank verified. Approved capabilities are now active.');
    }

    public function suspend(Request $request, Bank $bank, RecordAuditEvent $audit): RedirectResponse
    {
        Gate::authorize('verify', $bank);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        DB::transaction(function () use ($request, $bank, $audit, $data): void {
            $bank = Bank::query()->lockForUpdate()->findOrFail($bank->id);
            $bank->forceFill(['status' => BankStatus::Suspended, 'can_borrow' => false, 'can_lend' => false])->save();
            User::query()->whereIn('id', $bank->memberships()->select('user_id'))->increment('auth_version');
            $audit->handle($request->user(), 'bank.suspended', $bank, $data);
        });

        return back()->with('status', 'Bank suspended and existing sessions revoked.');
    }

    public function reopen(Request $request, Bank $bank, RecordAuditEvent $audit): RedirectResponse
    {
        Gate::authorize('verify', $bank);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        DB::transaction(function () use ($request, $bank, $audit, $data): void {
            $bank = Bank::query()->lockForUpdate()->findOrFail($bank->id);
            abort_unless($bank->status === BankStatus::Suspended, 409);
            $bank->forceFill(['status' => BankStatus::Pending, 'verified_at' => null, 'can_borrow' => false, 'can_lend' => false])->save();
            $audit->handle($request->user(), 'bank.reopened_for_review', $bank, $data);
        });

        return back()->with('status', 'Bank returned to onboarding. A new verification is required.');
    }
}
