<x-layout :title="$marketplace ? 'Marketplace' : 'Funding requests'">
    <x-page-header
        :title="$marketplace ? 'Marketplace' : 'Funding requests'"
        :meta="$marketplace ? 'Open requests from verified borrower banks. Review the profile and documents, then prepare an offer.' : 'Prepare requests, obtain independent approval and compare the offers you receive.'"
    >
        @if (! $marketplace)
            @can('create', \App\Models\FundingRequest::class)
                <a class="btn btn--primary" href="{{ route('funding.create') }}"><x-icon name="plus" /> New funding request</a>
            @endcan
        @endif
    </x-page-header>

    <section class="panel panel--flush">
        @if ($records->isEmpty())
            <x-empty-state
                :icon="$marketplace ? 'store' : 'file'"
                :title="$marketplace ? 'No open requests right now' : 'No funding requests yet'"
                :text="$marketplace ? 'Published requests appear here while their bidding window is open.' : 'A treasury maker can create a draft and attach the borrower\'s financial documents.'"
            >
                @if (! $marketplace)
                    @can('create', \App\Models\FundingRequest::class)
                        <a class="btn btn--primary" href="{{ route('funding.create') }}">Create the first request</a>
                    @endcan
                @endif
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Request</th>
                            <th>Amount</th>
                            <th>Tenor</th>
                            <th>Settlement</th>
                            <th>Bidding closes</th>
                            <th>{{ $marketplace ? 'Documents' : 'Status' }}</th>
                            <th><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td class="primary">
                                    <a class="cell-title" href="{{ route('funding.show', $record) }}">{{ $record->title }}</a>
                                    <span class="cell-sub">{{ $marketplace ? $record->borrower_profile['name'] : 'Ref '.strtoupper(substr($record->id, -8)) }}</span>
                                </td>
                                <td data-label="Amount"><x-money :currency="$record->currency" :amount="$record->amount" /></td>
                                <td data-label="Tenor">{{ $record->tenor_days }} days</td>
                                <td data-label="Settlement"><x-when :at="$record->settlement_date" date-only /></td>
                                <td data-label="Bidding closes"><x-when :at="$record->bidding_deadline" /></td>
                                @if ($marketplace)
                                    <td data-label="Documents">{{ $record->documents->count() }} {{ Str::plural('file', $record->documents->count()) }}</td>
                                @else
                                    <td data-label="Status"><x-status :value="$record->currentStatus()->value" :label="$record->currentStatus()->label()" /></td>
                                @endif
                                <td class="action"><a class="btn btn--secondary btn--sm" href="{{ route('funding.show', $record) }}">{{ $marketplace ? 'Review' : 'Open' }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $records->links() }}
        @endif
    </section>
</x-layout>
