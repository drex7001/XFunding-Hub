<?php

namespace App\Http\Controllers;

use App\Actions\RecordDealActivity;
use App\Models\Deal;
use App\Notifications\DealActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DealNotificationController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);
        $records = $this->visible($request)->latest()->paginate(20);
        $data = $records->through(fn (DatabaseNotification $notification): array => [
            'id' => $notification->id, 'message' => RecordDealActivity::label($notification->data['kind'], $notification->data['side']),
            'dealUrl' => route('deals.show', $notification->data['dealId']),
            'at' => $notification->created_at->toIso8601String(), 'readAt' => $notification->read_at?->toIso8601String(),
        ]);

        return $request->expectsJson() || $request->is('api/*') ? response()->json($data) : view('deals.notifications', ['records' => $data]);
    }

    public function read(Request $request, string $notification): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);
        $record = $this->visible($request)->findOrFail($notification);
        $record->markAsRead();

        return $request->expectsJson() || $request->is('api/*') ? response()->json(['data' => ['id' => $record->id, 'read' => true]]) : back()->with('status', 'Notification marked as read.');
    }

    private function visible(Request $request): Builder
    {
        $bankId = $request->user()->membership->bank_id;
        $roles = $request->user()->membership->roles;

        return $request->user()->notifications()->getQuery()->where('type', DealActivityNotification::class)
            ->where('data->bankId', $bankId)
            ->whereIn('data->dealId', Deal::query()->select('id')->where(fn (Builder $query): Builder => $query->where('borrower_bank_id', $bankId)->orWhere('lender_bank_id', $bankId)))
            ->where(function (Builder $query) use ($roles): void {
                foreach ($roles as $role) {
                    $query->orWhereJsonContains('data->roles', $role);
                }
            });
    }
}
