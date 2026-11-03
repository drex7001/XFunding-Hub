<x-layout title="Your offers">
    <x-page-header title="Your offers" meta="Drafts, approvals and bids from your institution.">
        @can('viewMarketplace', \App\Models\FundingRequest::class)
            <a class="btn btn--primary" href="{{ route('marketplace') }}">Browse the marketplace</a>
        @endcan
    </x-page-header>

    <section class="panel panel--flush">
        @if ($records->isEmpty())
            <x-empty-state icon="tag" title="No offers yet" text="A lender maker can prepare an offer from a published funding request." />
        @else
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Request</th>
                            <th>Your rate</th>
                            <th>Coverage</th>
                            <th>Valid until</th>
                            <th>Status</th>
                            <th><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td class="primary">
                                    <a class="cell-title" href="{{ route('offers.show', $record) }}">{{ $record->fundingRequest->title }}</a>
                                    <span class="cell-sub">{{ $record->fundingRequest->borrower_profile['name'] }}</span>
                                </td>
                                <td data-label="Your rate"><span class="rate">{{ $record->rate }}%</span></td>
                                <td data-label="Coverage"><x-money :currency="$record->fundingRequest->currency" :amount="$record->fundingRequest->amount" /> · {{ $record->fundingRequest->tenor_days }} days</td>
                                <td data-label="Valid until"><x-when :at="$record->valid_until" /></td>
                                <td data-label="Status"><x-status :value="$record->currentStatus()->value" :label="$record->currentStatus()->label()" /></td>
                                <td class="action"><a class="btn btn--secondary btn--sm" href="{{ route('offers.show', $record) }}">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $records->links() }}
        @endif
    </section>
</x-layout>
