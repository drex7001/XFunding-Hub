<x-layout title="New password" :guest="true">
<main class="auth--single">
    <section class="auth__card">
        <a class="brand auth__brand" href="{{ route('login') }}"><span class="brand-mark">X</span> XFunding<span class="brand-light">Hub</span></a>
        @if (session('status'))
            <div class="alert alert--success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert--danger" role="alert">{{ $errors->first() }}</div>
        @endif
        <h2>Choose a new password</h2>
        <p>Use at least 12 characters, with uppercase and lowercase letters and a number.</p>
        <form method="post" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="email">Work email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required>
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            <label for="confirmation">Confirm password</label>
            <input id="confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            <button class="btn btn--primary btn--full">Update password</button>
        </form>
    </section>
</main>
</x-layout>
