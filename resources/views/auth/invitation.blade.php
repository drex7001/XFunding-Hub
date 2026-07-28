<x-layout title="Accept invitation" :guest="true"><section class="auth-card single"><span class="eyebrow">YOU'RE INVITED</span><h2>Join {{ $invitation->bank->name }}</h2><p class="muted">{{ $invitation->name }} · {{ $invitation->email }}</p><div class="role-list">
@foreach ($invitation->roles as $role)<span class="tag">{{ \App\BankRole::from($role)->label() }}</span>
@endforeach</div>
<p>Choose a password with at least 12 characters, uppercase and lowercase letters, and a number.</p><form method="post" action="{{ route('invitation.accept', $token) }}">
@csrf<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required><label for="confirmation">Confirm password</label><input id="confirmation" name="password_confirmation" type="password" autocomplete="new-password" required><button class="button full">Accept invitation</button></form>
<p class="muted">Expires {{ $invitation->expires_at->format('d M Y, H:i') }} UTC.</p></section>
</x-layout>
