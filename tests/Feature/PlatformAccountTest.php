<?php

use App\BankRole;
use App\Models\AuditEvent;
use App\Models\BankInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('provisions the first platform account from the console in production with a password setup link', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('platform:create-admin', ['email' => 'New.Admin@Example.test', 'name' => 'New Admin'])->assertSuccessful();

    $account = User::query()->where('email', 'new.admin@example.test')->sole();
    expect($account->is_platform_admin)->toBeTrue()->and($account->fresh()->active)->toBeTrue();
    Notification::assertSentTo($account, ResetPassword::class);
    $this->assertDatabaseHas('audit_events', ['action' => 'platform.account_created', 'actor_id' => null, 'subject_id' => (string) $account->id]);
});

it('refuses console provisioning for an invalid or existing email', function (string $email) {
    User::factory()->create(['email' => 'taken@example.test']);
    BankInvitation::factory()->create(['email' => 'invited@example.test']);

    $this->artisan('platform:create-admin', ['email' => $email, 'name' => 'New Admin'])->assertFailed();

    expect(User::query()->where('email', $email)->where('is_platform_admin', true)->exists())->toBeFalse();
})->with(['not-an-email', 'taken@example.test', 'invited@example.test']);

it('lets platform operations add an account and denies bank staff', function () {
    $payload = ['name' => 'Second Operator', 'email' => 'second@example.test', 'reason' => 'Additional operations coverage.'];
    signInUser(bankStaff(BankRole::Admin));
    $this->get(route('platform-accounts.index'))->assertForbidden();
    $this->post(route('platform-accounts.store'), $payload)->assertForbidden();

    signInUser(User::factory()->platformAdmin()->create());
    $this->get(route('platform-accounts.index'))->assertOk()->assertSee('Add an operations account');
    $this->post(route('platform-accounts.store'), $payload + ['is_platform_admin' => false])->assertSessionHasErrors('is_platform_admin');
    $this->post(route('platform-accounts.store'), $payload)->assertRedirect(route('platform-accounts.index'));

    $account = User::query()->where('email', 'second@example.test')->sole();
    expect($account->is_platform_admin)->toBeTrue();
    Notification::assertSentTo($account, ResetPassword::class);
    expect(AuditEvent::query()->where('action', 'platform.account_created')->sole()->context['reason'])->toBe('Additional operations coverage.');
});

it('deactivates another platform account, revokes its sessions and can restore it', function () {
    $operator = User::factory()->platformAdmin()->create();
    $other = User::factory()->platformAdmin()->create();
    signInUser($operator);
    $reason = ['reason' => 'Operator left the team.'];

    $this->post(route('platform-accounts.deactivate', $other), [])->assertSessionHasErrors('reason');
    $this->post(route('platform-accounts.deactivate', $other), $reason)->assertRedirect();
    expect($other->fresh()->active)->toBeFalse()->and($other->fresh()->auth_version)->toBe($other->auth_version + 1);
    $this->post(route('platform-accounts.deactivate', $other), $reason)->assertRedirect()->assertSessionHasErrors('conflict');

    signInUser($other->fresh());
    $this->getJson('/api/v1/me')->assertUnauthorized();

    signInUser($operator);
    $this->post(route('platform-accounts.reactivate', $other), ['reason' => 'Operator returned to the team.'])->assertRedirect();
    expect($other->fresh()->active)->toBeTrue();
});

it('refuses self deactivation and changes to bank accounts', function () {
    $operator = User::factory()->platformAdmin()->create();
    signInUser($operator);
    $reason = ['reason' => 'Attempted account change.'];

    $this->post(route('platform-accounts.deactivate', $operator), $reason)->assertForbidden();
    $this->post(route('platform-accounts.deactivate', bankStaff(BankRole::Maker)), $reason)->assertNotFound();
    expect($operator->fresh()->active)->toBeTrue();
});
