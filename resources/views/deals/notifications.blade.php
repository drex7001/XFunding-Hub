@php($hasUnread = collect($records->items())->contains(fn (array $record): bool => $record['readAt'] === null))
<x-layout title="Notifications">
    <x-page-header title="Notifications" meta="Actions and outcomes for your current institution role.">
        @if ($hasUnread)
            <form method="post" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="btn btn--secondary">Mark all as read</button>
            </form>
        @endif
    </x-page-header>

    <section class="panel panel--flush">
        @forelse ($records as $record)
            <div @class(['row row--flush', 'row--unread' => ! $record['readAt']])>
                <div class="row__text">
                    <strong>{{ $record['message'] }}</strong>
                    <small><x-when :at="$record['at']" /></small>
                </div>
                <div class="row-actions">
                    @unless ($record['readAt'])
                        <form method="post" action="{{ route('notifications.read', $record['id']) }}">
                            @csrf
                            <button class="link-button">Mark as read</button>
                        </form>
                    @endunless
                    <a class="btn btn--secondary btn--sm" href="{{ $record['dealUrl'] }}">Open deal</a>
                </div>
            </div>
        @empty
            <x-empty-state icon="bell" title="No notifications" text="Updates for your current role appear here." />
        @endforelse
        {{ $records->links() }}
    </section>
</x-layout>
