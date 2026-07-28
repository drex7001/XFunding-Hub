<x-layout title="Sign in" :guest="true">
<section class="auth-story"><a class="brand" href="{{ route('login') }}"><span class="brand-mark">X</span> XFunding Hub</a><div><span class="eyebrow">INTERBANK LIQUIDITY</span><h1>A trusted space<br>for bank treasury.</h1><p>Connect your institution to a controlled banking marketplace, with independent approvals at every important step.</p></div>
<small>Bank identities verified. Staff access controlled.</small></section>
<section class="auth-card"><span class="eyebrow">WELCOME BACK</span><h2>Sign in to your workspace</h2><p class="muted">Use the account invited by your bank administrator.</p><form method="post" action="{{ route('login.store') }}">
@csrf<label for="email">Work email</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" placeholder="you@bank.com" required autofocus><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required><a class="small-link" href="{{ route('password.request') }}">Forgot your password?</a><button class="button full">Sign in <span>→</span></button></form>
<p class="auth-foot">Need access? Ask your bank administrator for an invitation.</p></section>
</x-layout>
