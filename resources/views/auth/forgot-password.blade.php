<x-layout title="Reset password" :guest="true">
<main class="auth--single">
    <section class="auth__card">
        <a class="brand auth__brand" href="{{ route('login') }}"><span class="brand-mark">X</span> XFunding<span class="brand-light">Hub</span></a>
        @if (session('status'))
            <div class="alert alert--success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert--danger" role="alert">{{ $errors->first() }}</div>
        @endif
        <h2>Reset your password</h2>
        <p>We'll send a reset link if an account exists for this email.</p>
        <form method="post" action="{{ route('password.email') }}">
            @csrf
            <label for="email">Work email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            <button class="btn btn--primary btn--full">Send reset link</button>
        </form>
        <p class="auth__aside"><a href="{{ route('login') }}">Back to sign in</a></p>
    </section>
</main>
</x-layout>
