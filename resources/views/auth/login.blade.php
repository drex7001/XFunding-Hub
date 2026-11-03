<x-layout title="Sign in" :guest="true">
    <div class="auth">
        <section class="auth__story">
            <a class="brand" href="{{ route('login') }}"><span class="brand-mark">X</span> XFunding<span class="brand-light">Hub</span></a>
            <div>
                <h1>Interbank liquidity, with control at every step.</h1>
                <p>Publish funding needs, compare blind offers and settle through your own official channels.</p>
            </div>
            <ul class="auth__points">
                <li><x-icon name="check" /> Every institution is verified</li>
                <li><x-icon name="check" /> Independent approval on every treasury action</li>
                <li><x-icon name="check" /> Lender identity stays locked until the fee is verified</li>
            </ul>
        </section>
        <section class="auth__panel">
            <div class="auth__card">
                @if (session('status'))
                    <div class="alert alert--success" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert--danger" role="alert">{{ $errors->first() }}</div>
                @endif
                <h2>Sign in to your workspace</h2>
                <p>Use the account invited by your bank administrator.</p>
                <form method="post" action="{{ route('login.store') }}">
                    @csrf
                    <label for="email">Work email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" placeholder="you@bank.com" required autofocus>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <div class="auth__row"><a href="{{ route('password.request') }}">Forgot your password?</a></div>
                    <button class="btn btn--primary btn--full">Sign in</button>
                </form>
                <p class="auth__aside">Need access? Ask your bank administrator for an invitation.</p>
            </div>
        </section>
    </div>
</x-layout>
