@php
    $user = auth()->user();
    $firstName = explode(' ', trim($user->name))[0];
    $bank = $user->membership?->bank;
    $actions = $queue['actions'];
@endphp
<x-layout title="Home" :queue="$queue">
    <x-page-header
        :title="'Welcome back, '.$firstName"
        :meta="$user->is_platform_admin ? 'Platform operations' : $bank->name.' · '.collect($user->membership->roles)->map(fn (string $role): string => \App\BankRole::from($role)->label())->implode(', ')"
    />

    @foreach ($queue['setup'] as $step)
        <x-next-step :title="$step['task']" kicker="Set up first">
            <p>{{ $step['detail'] }}</p>
            <div class="btn-row"><a class="btn btn--primary" href="{{ $step['url'] }}">{{ $step['cta'] }}</a></div>
        </x-next-step>
    @endforeach

    <div class="grid-main">
        <section class="panel panel--flush">
            <div class="panel__head panel__head--flush">
                <h2>Needs your action</h2>
                @if ($actions !== [])
                    <span class="badge badge--warning">{{ count($actions) }}</span>
                @endif
            </div>
            @if ($actions === [])
                <x-empty-state title="You're all caught up" text="Nothing is waiting for you right now. Approvals and next steps for your role appear here." />
            @else
                <div class="work-list">
                    @foreach ($actions as $item)
                        <x-work-item :item="$item" />
                    @endforeach
                </div>
            @endif
        </section>

        <div class="stack">
            @if ($queue['waiting'] !== [])
                <section class="panel panel--flush">
                    <div class="panel__head panel__head--flush"><h2>In progress</h2></div>
                    <div class="work-list">
                        @foreach ($queue['waiting'] as $item)
                            <x-work-item :item="$item" :waiting="true" />
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="panel">
                <h2>Go to</h2>
                <div class="quick-links">
                    @if ($user->is_platform_admin)
                        <a class="quick-link" href="{{ route('operations') }}"><x-icon name="bank" /> Institutions</a>
                        <a class="quick-link" href="{{ route('disputes.operations') }}"><x-icon name="alert" /> Settlement disputes</a>
                        <a class="quick-link" href="{{ route('payments.operations') }}"><x-icon name="card" /> Payment exceptions</a>
                        <a class="quick-link" href="{{ route('platform-accounts.index') }}"><x-icon name="users" /> Platform accounts</a>
                    @else
                        @can('create', \App\Models\FundingRequest::class)
                            <a class="quick-link" href="{{ route('funding.create') }}"><x-icon name="plus" /> New funding request</a>
                        @endcan
                        @can('viewMarketplace', \App\Models\FundingRequest::class)
                            <a class="quick-link" href="{{ route('marketplace') }}"><x-icon name="store" /> Browse the marketplace</a>
                        @endcan
                        @can('viewAny', \App\Models\Deal::class)
                            <a class="quick-link" href="{{ route('deals.index') }}"><x-icon name="layers" /> Selected deals</a>
                        @endcan
                        @can('viewAudit', $bank)
                            <a class="quick-link" href="{{ route('banks.audit', $bank) }}"><x-icon name="shield" /> Audit history</a>
                        @endcan
                        <a class="quick-link" href="{{ route('banks.show', $bank) }}"><x-icon name="bank" /> {{ $bank->name }}</a>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-layout>
