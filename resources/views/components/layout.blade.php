
@props(['title' => 'Bank workspace', 'guest' => false])
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }} · XFunding Hub</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head>
<body class="{{ $guest ? 'guest' : 'workspace' }}">
@if (!$guest)
<aside class="sidebar"><a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">X</span> XFunding<span class="brand-light">Hub</span></a><p class="nav-label">BANKING WORKSPACE</p><a class="nav-item {{ request()->routeIs('banks.*', 'dashboard', 'operations') ? 'active' : '' }}" href="{{ route('dashboard') }}">{{ auth()->user()->is_platform_admin ? 'Bank operations' : 'My bank' }} <span>↗</span></a>
@can('viewAny', \App\Models\FundingRequest::class)
    @if (auth()->user()->membership->bank->can_borrow)
        <a class="nav-item {{ request()->routeIs('funding.*') ? 'active' : '' }}" href="{{ route('funding.index') }}">Funding requests <span>↗</span></a>
    @endif
@endcan
@can('viewMarketplace', \App\Models\FundingRequest::class)
    <a class="nav-item {{ request()->routeIs('marketplace') ? 'active' : '' }}" href="{{ route('marketplace') }}">Marketplace <span>↗</span></a>
@endcan
@can('viewAny', \App\Models\Offer::class)
    <a class="nav-item {{ request()->routeIs('offers.*') ? 'active' : '' }}" href="{{ route('offers.index') }}">Your offers <span>↗</span></a>
@endcan
@can('viewAny', \App\Models\SettlementInstruction::class)
    <a class="nav-item {{ request()->routeIs('instructions.*') ? 'active' : '' }}" href="{{ route('instructions.index') }}">Settlement instructions <span>↗</span></a>
@endcan
@can('viewAny', \App\Models\Deal::class)
    <a class="nav-item {{ request()->routeIs('deals.*') ? 'active' : '' }}" href="{{ route('deals.index') }}">Selected deals <span>↗</span></a>
@endcan
@can('viewAny', \App\Models\Deal::class)
    <a class="nav-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}" href="{{ route('notifications.index') }}">Notifications <span>↗</span></a>
@endcan
@can('viewAny', \App\Models\DealDispute::class)
    <a class="nav-item {{ request()->routeIs('disputes.*') ? 'active' : '' }}" href="{{ route('disputes.operations') }}">Settlement disputes <span>↗</span></a>
@endcan
@can('viewAny', \App\Models\PaymentReceipt::class)
    <a class="nav-item {{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.operations') }}">Payment exceptions <span>↗</span></a>
@endcan
<div class="sidebar-note"><span class="eyebrow">BUILT ON TRUST</span><p>Controlled access.<br>Clear accountability.</p></div>
<div class="profile"><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->is_platform_admin ? 'Platform operations' : auth()->user()->membership->bank->name }}</small></div>
</div>
<form method="post" action="{{ route('logout') }}">
@csrf<button class="signout">Sign out</button></form>
</aside>
@endif
<main class="{{ $guest ? 'auth-shell' : 'main' }}">
@if (!$guest)<header class="topbar"><span>XFunding Hub <span class="muted">/ {{ $title }}</span></span><span class="tag">Bank access</span></header>
@endif
@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())<div class="errors" role="alert"><strong>Please check the following.</strong><ul>
@foreach ($errors->all() as $error)<li>{{ $error }}</li>
@endforeach</ul></div>
@endif
{{ $slot }}
</main>
</body></html>
