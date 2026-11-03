<?php

namespace App\Http\Controllers;

use App\Actions\RecordDealActivity;
use App\Actions\VisibleDealNotifications;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DealNotificationController extends Controller
{
    public function index(Request $request, VisibleDealNotifications $notifications): View|JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);
        $records = $notifications->for($request->user())->latest()->paginate(20);
        $data = $records->through(fn (DatabaseNotification $notification): array => [
            'id' => $notification->id, 'message' => RecordDealActivity::label($notification->data['kind'], $notification->data['side']),
            'dealUrl' => route('deals.show', $notification->data['dealId']),
            'at' => $notification->created_at->toIso8601String(), 'readAt' => $notification->read_at?->toIso8601String(),
        ]);

        return $request->expectsJson() || $request->is('api/*') ? response()->json($data) : view('deals.notifications', ['records' => $data]);
    }

    public function read(Request $request, string $notification, VisibleDealNotifications $notifications): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);
        $record = $notifications->for($request->user())->findOrFail($notification);
        $record->markAsRead();

        return $request->expectsJson() || $request->is('api/*') ? response()->json(['data' => ['id' => $record->id, 'read' => true]]) : back()->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request, VisibleDealNotifications $notifications): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);
        $updated = $notifications->for($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() || $request->is('api/*') ? response()->json(['data' => ['read' => $updated]]) : back()->with('status', 'All notifications marked as read.');
    }
}
