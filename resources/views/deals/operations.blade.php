<x-layout title="Settlement disputes">
    <x-page-header title="Settlement disputes" meta="Review institution attestations and record a reasoned outcome. The platform does not execute or reverse transfers." />

    <section class="panel panel--flush">
        @forelse ($records as $record)
            <div class="row row--flush">
                <div class="row__text">
                    <strong>Deal {{ strtoupper(substr($record->deal_id, -8)) }}</strong>
                    <small>Opened <x-when :at="$record->created_at" /></small>
                </div>
                <x-status :value="$record->status === 'open' ? 'disputed' : $record->status" :label="ucfirst(str_replace('_', ' ', $record->status))" />
                <form class="row-form" method="post" action="{{ route('disputes.review', $record) }}">
                    @csrf
                    <div>
                        <label for="reason-{{ $record->id }}">Access reason</label>
                        <input id="reason-{{ $record->id }}" name="reason" required minlength="8" maxlength="500" placeholder="Why you need to read the evidence">
                    </div>
                    <button class="btn btn--secondary">Review dispute</button>
                </form>
            </div>
        @empty
            <x-empty-state title="No settlement disputes recorded" />
        @endforelse
        {{ $records->links() }}
    </section>
</x-layout>
