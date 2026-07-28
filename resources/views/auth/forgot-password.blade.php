<x-layout title="Reset password" :guest="true"><section class="auth-card single"><a class="small-link" href="{{ route('login') }}">← Back to sign in</a><h2>Reset your password</h2><p class="muted">We'll send a reset link if an account exists for this email.</p><form method="post" action="{{ route('password.email') }}">
@csrf<label for="email">Work email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required><button class="button full">Send reset link</button></form>
</section>
</x-layout>
