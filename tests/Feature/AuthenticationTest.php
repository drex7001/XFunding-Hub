<?php

use App\Models\AuditEvent;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('renders the invitation-only login page', function () {
    $this->get(route('login'))->assertOk()->assertSee('Sign in to your workspace');
});

it('requires authentication for the account API', function () {
    $this->getJson(route('api.me'))->assertUnauthorized();
});

it('signs in a bank user and records the authenticated session', function () {
    $user = bankStaff();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard'))->assertSessionHas('auth_version', 0);

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas(AuditEvent::class, ['actor_id' => $user->id, 'action' => 'account.signed_in']);
});

it('returns a generic error for incorrect or inactive credentials', function (bool $active) {
    $user = User::factory()->platformAdmin()->create(['active' => $active]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => $active ? 'incorrect' : 'password'])
        ->assertSessionHasErrors(['email' => 'These credentials are not available. Check your email and password.']);

    $this->assertGuest();
    $this->assertDatabaseCount('audit_events', 0);
})->with(['wrong password' => true, 'inactive account' => false]);

it('blocks a sixth login attempt within a minute with 429', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('login.store'), ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertUnprocessable();
    }
    $this->postJson(route('login.store'), ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertTooManyRequests();
});

it('invalidates a session after its account version changes with 401', function () {
    $user = bankStaff();
    signInUser($user);
    User::query()->whereKey($user->id)->increment('auth_version');

    $this->getJson(route('api.me'))->assertUnauthorized();

    $this->assertGuest();
});

it('refuses a revoked bank membership with 403', function () {
    $user = bankStaff();
    signInUser($user);
    $user->membership->update(['active' => false]);

    $this->getJson(route('api.me'))->assertForbidden();
});

it('refuses a suspended bank with 403', function () {
    $user = bankStaff(bank: Bank::factory()->suspended()->create());
    signInUser($user);

    $this->getJson(route('api.me'))->assertForbidden();
});

it('logs out and removes the session version', function () {
    $user = bankStaff();
    signInUser($user);

    $this->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('auth_version');

    $this->assertGuest();
});

it('normalizes stored and submitted email addresses for sign in', function () {
    $user = User::factory()->platformAdmin()->create(['email' => 'OPERATIONS@EXAMPLE.TEST']);

    $this->post(route('login.store'), ['email' => 'Operations@Example.Test', 'password' => 'password'])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'operations@example.test']);
});

it('sends an existing user a password reset notification', function () {
    $user = bankStaff();
    Notification::fake();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status', 'If this account exists, a password reset link has been sent.');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('does not disclose whether a password reset email exists', function () {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'missing@example.test'])->assertSessionHas('status', 'If this account exists, a password reset link has been sent.');

    Notification::assertNothingSent();
});

it('resets a password and revokes previous sessions', function () {
    $user = bankStaff();
    $token = Password::createToken($user);

    $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'NewSecureBank123!', 'password_confirmation' => 'NewSecureBank123!'])->assertRedirect(route('login'));

    expect(Hash::check('NewSecureBank123!', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->auth_version)->toBe(1);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'action' => 'account.password_reset']);
});
