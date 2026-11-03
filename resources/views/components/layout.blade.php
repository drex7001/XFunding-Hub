@props(['title' => 'Workspace', 'guest' => false, 'queue' => null])
@php
    $user = $guest ? null : auth()->user();
    $workQueue = $user ? app(\App\Actions\BuildWorkQueue::class) : null;
    $actionCount = $user ? count(($queue ?? $workQueue->for($user))['actions']) : 0;
    $unreadCount = $user ? $workQueue->unreadNotifications($user) : 0;
    $roleNames = $user?->membership ? collect($user->membership->roles)->map(fn (string $role): string => \App\BankRole::from($role)->label())->implode(', ') : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · XFunding Hub</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ filemtime(public_path('app.css')) }}">
</head>
<body>
@if ($guest)
    {{ $slot }}
@else
    <a class="skip-link" href="#content">Skip to content</a>
    <div class="app">
        <aside class="sidebar">
            <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">X</span> XFunding<span class="brand-light">Hub</span></a>
            <input class="nav-toggle" id="nav-toggle" type="checkbox" aria-label="Show navigation">
            <label class="menu-button" for="nav-toggle"><x-icon name="menu" /><span class="sr-only">Menu</span></label>
            <nav class="nav" aria-label="Main">
                <div class="nav-group">
                    <x-nav-item :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')" :count="$actionCount">Home</x-nav-item>
                    @can('viewAny', \App\Models\Deal::class)
                        <x-nav-item :href="route('notifications.index')" icon="bell" :active="request()->routeIs('notifications.*')" :count="$unreadCount" :quiet="true">Notifications</x-nav-item>
                    @endcan
                </div>
                @if ($user->is_platform_admin)
                    <div class="nav-group">
                        <p class="nav-label">Operations</p>
                        <x-nav-item :href="route('operations')" icon="bank" :active="request()->routeIs('operations', 'banks.*')">Institutions</x-nav-item>
                        <x-nav-item :href="route('disputes.operations')" icon="alert" :active="request()->routeIs('disputes.*')">Settlement disputes</x-nav-item>
                        <x-nav-item :href="route('payments.operations')" icon="card" :active="request()->routeIs('payments.*')">Payment exceptions</x-nav-item>
                        <x-nav-item :href="route('platform-accounts.index')" icon="users" :active="request()->routeIs('platform-accounts.*')">Platform accounts</x-nav-item>
                    </div>
                @else
                    @if ($user->hasVerifiedBankAccess() && $user->membership->bank->can_borrow)
                        <div class="nav-group">
                            <p class="nav-label">Borrowing</p>
                            <x-nav-item :href="route('funding.index')" icon="file" :active="request()->routeIs('funding.*')">Funding requests</x-nav-item>
                        </div>
                    @endif
                    @if ($user->can('viewMarketplace', \App\Models\FundingRequest::class) || $user->can('viewAny', \App\Models\Offer::class))
                        <div class="nav-group">
                            <p class="nav-label">Lending</p>
                            @can('viewMarketplace', \App\Models\FundingRequest::class)
                                <x-nav-item :href="route('marketplace')" icon="store" :active="request()->routeIs('marketplace')">Marketplace</x-nav-item>
                            @endcan
                            @can('viewAny', \App\Models\Offer::class)
                                <x-nav-item :href="route('offers.index')" icon="tag" :active="request()->routeIs('offers.*')">Your offers</x-nav-item>
                            @endcan
                        </div>
                    @endif
                    @if ($user->can('viewAny', \App\Models\Deal::class))
                        <div class="nav-group">
                            <p class="nav-label">Treasury</p>
                            <x-nav-item :href="route('deals.index')" icon="layers" :active="request()->routeIs('deals.*')">Selected deals</x-nav-item>
                            @can('viewAny', \App\Models\SettlementInstruction::class)
                                <x-nav-item :href="route('instructions.index')" icon="key" :active="request()->routeIs('instructions.*')">Settlement instructions</x-nav-item>
                            @endcan
                        </div>
                    @endif
                    <div class="nav-group">
                        <p class="nav-label">Institution</p>
                        <x-nav-item :href="route('banks.show', $user->membership->bank_id)" icon="bank" :active="request()->routeIs('banks.show')">My bank</x-nav-item>
                        @can('viewAudit', $user->membership->bank)
                            <x-nav-item :href="route('banks.audit', $user->membership->bank_id)" icon="shield" :active="request()->routeIs('banks.audit')">Audit history</x-nav-item>
                        @endcan
                    </div>
                @endif
            </nav>
            <div class="profile">
                <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                <div class="profile-text">
                    <strong>{{ $user->name }}</strong>
                    <small>{{ $user->is_platform_admin ? 'Platform operations' : $user->membership->bank->name }}</small>
                    @if ($roleNames)
                        <small>{{ $roleNames }}</small>
                    @endif
                </div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="signout" title="Sign out"><x-icon name="logout" /><span class="sr-only">Sign out</span></button>
                </form>
            </div>
        </aside>
        <main class="main" id="content">
            <div class="container">
                @if (session('status'))
                    <div class="alert alert--success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert--danger" role="alert">
                        @if ($errors->count() === 1)
                            {{ $errors->first() }}
                        @else
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
                {{ $slot }}
            </div>
        </main>
    </div>
@endif
</body>
</html>
