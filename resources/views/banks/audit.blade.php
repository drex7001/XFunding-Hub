<x-layout title="Audit history">
    <x-page-header
        title="Audit history"
        :eyebrow="$bank->name"
        :back="route('banks.show', $bank)"
        :back-label="auth()->user()->is_platform_admin ? $bank->name : 'My bank'"
        meta="Who did what and when at your institution. Private reasons and banking details are not shown."
    />

    <section class="panel panel--flush">
        @if ($rows === [])
            <x-empty-state icon="shield" title="No activity yet" text="Your institution's activity will appear here." />
        @else
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead><tr><th>Action</th><th>By</th><th>Subject</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="primary"><span class="cell-title">{{ $row['label'] }}</span></td>
                                <td data-label="By">{{ $row['actor'] }}</td>
                                <td data-label="Subject">{{ $row['subject'] }} <x-ref :id="$row['subjectId']" /></td>
                                <td data-label="When"><x-when :at="$row['at']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $records->links() }}
        @endif
    </section>
</x-layout>
