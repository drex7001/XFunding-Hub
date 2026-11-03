<x-layout title="Accept invitation" :guest="true">
<main class="auth--single">
    <section class="auth__card">
        <a class="brand auth__brand" href="{{ route('login') }}"><span class="brand-mark">X</span> XFunding<span class="brand-light">Hub</span></a>
        @if (session('status'))
            <div class="alert alert--success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert--danger" role="alert">{{ $errors->first() }}</div>
        @endif
        <h2>Join {{ $invitation->bank->name }}</h2>
        <p>{{ $invitation->name }} · {{ $invitation->email }}</p>
        <div class="role-list">
            @foreach ($invitation->roles as $role)
                <span class="tag">{{ \App\BankRole::from($role)->label() }}</span>
            @endforeach
        </div>
        <form method="post" action="{{ route('invitation.accept', $token) }}">
            @csrf
            <label for="password">Choose a password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            <span class="hint">At least 12 characters, with uppercase and lowercase letters and a number.</span>
            <label for="confirmation">Confirm password</label>
            <input id="confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            <button class="btn btn--primary btn--full">Accept invitation</button>
        </form>
        <p class="auth__aside">This invitation expires <x-when :at="$invitation->expires_at" />.</p>
    </section>
</main>
</x-layout>
