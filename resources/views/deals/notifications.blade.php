<x-layout title="Notifications">
    <div class="page-heading"><div><span class="eyebrow">INSTITUTION ACTIVITY</span><h1>Your notifications.</h1><p class="muted">Actions and outcomes for your current institution role.</p></div></div>
    <section class="panel">
        @forelse ($records as $record)
            <div class="review-row"><div><strong>{{ $record['message'] }}</strong><small>{{ $record['at'] }} · {{ $record['readAt'] ? 'Read' : 'Unread' }}</small><a class="small-link" href="{{ $record['dealUrl'] }}">Open deal →</a></div>
                @if (!$record['readAt'])<form method="post" action="{{ route('notifications.read', $record['id']) }}">@csrf<button class="button secondary">Mark as read</button></form>@endif
            </div>
        @empty<p class="empty">No notifications for your current role.</p>@endforelse
        {{ $records->links() }}
    </section>
</x-layout>
