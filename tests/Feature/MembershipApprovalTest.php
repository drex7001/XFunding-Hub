<?php

use App\BankRole;
use App\Models\AccessRequest;
use App\Models\Bank;
use App\Models\BankInvitation;
use App\Models\User;
use App\Notifications\BankInvitationNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

it('creates an access proposal without prematurely sending an invitation', function () {
    $admin = bankStaff(BankRole::Admin);
    signInUser($admin);
    Notification::fake();

    $this->post(route('access.store'), ['name' => 'Colleague', 'email' => 'colleague@example.test', 'roles' => ['treasury_maker']])->assertRedirect();

    $this->assertDatabaseHas('access_requests', ['bank_id' => $admin->membership->bank_id, 'requested_by' => $admin->id, 'status' => 'pending']);
    $this->assertDatabaseCount('bank_invitations', 0);
    Notification::assertNothingSent();
});

it('refuses self approval with 403', function () {
    $admin = bankStaff(BankRole::Admin);
    $request = AccessRequest::factory()->create(['bank_id' => $admin->membership->bank_id, 'requested_by' => $admin->id]);
    signInUser($admin);

    $this->postJson(route('access.review', $request), ['decision' => 'approve'])->assertForbidden();

    expect($request->fresh()->status)->toBe('pending');
    $this->assertDatabaseCount('bank_invitations', 0);
});

it('hides another banks access request with 404', function () {
    $admin = bankStaff(BankRole::Admin);
    $request = AccessRequest::factory()->create();
    signInUser($admin);

    $this->postJson(route('access.review', $request), ['decision' => 'approve'])->assertNotFound();
});

it('does not allow a treasury role to administer memberships', function (BankRole $role) {
    $bank = Bank::factory()->create();
    $user = bankStaff($role, $bank);
    $request = AccessRequest::factory()->create(['bank_id' => $bank->id]);

    expect(Gate::forUser($user)->allows('review', $request))->toBeFalse();
    expect(Gate::forUser($user)->allows('manageMembers', $bank))->toBeFalse();
})->with([BankRole::Maker, BankRole::Approver, BankRole::Auditor]);

it('queues an invitation after independent platform approval', function () {
    $request = AccessRequest::factory()->create();
    signInUser(User::factory()->platformAdmin()->create());
    Notification::fake();

    $this->post(route('access.review', $request), ['decision' => 'approve'])->assertRedirect();

    expect($request->fresh()->status)->toBe('approved');
    $this->assertDatabaseHas('bank_invitations', ['email' => $request->email, 'bank_id' => $request->bank_id]);
    $this->assertDatabaseMissing('users', ['email' => $request->email]);
    Notification::assertSentOnDemand(BankInvitationNotification::class);
});

it('applies independently approved roles and revokes previous sessions', function () {
    $bank = Bank::factory()->create();
    $maker = bankStaff(bank: $bank);
    $requester = bankStaff(BankRole::Admin, $bank);
    $reviewer = bankStaff(BankRole::Admin, $bank);
    $request = AccessRequest::factory()->create(['bank_id' => $bank->id, 'membership_id' => $maker->membership->id, 'membership_version' => 0, 'requested_by' => $requester->id, 'kind' => 'role_change', 'roles' => ['treasury_approver']]);
    signInUser($reviewer);

    $this->post(route('access.review', $request), ['decision' => 'approve'])->assertRedirect();

    expect($maker->membership->fresh()->roles)->toBe(['treasury_approver']);
    expect($maker->fresh()->auth_version)->toBe(1);
    $this->assertDatabaseHas('audit_events', ['action' => 'access.approved', 'subject_id' => (string) $request->id]);
});

it('refuses promotion approval by the user being promoted with 403', function () {
    $bank = Bank::factory()->create();
    $target = bankStaff(BankRole::Admin, $bank);
    $requester = bankStaff(BankRole::Admin, $bank);
    $request = AccessRequest::factory()->create(['bank_id' => $bank->id, 'membership_id' => $target->membership->id, 'membership_version' => 0, 'requested_by' => $requester->id, 'kind' => 'role_change', 'roles' => ['bank_admin', 'treasury_approver']]);
    signInUser($target);

    $this->postJson(route('access.review', $request), ['decision' => 'approve'])->assertForbidden();

    expect($target->membership->fresh()->roles)->toBe(['bank_admin']);
});

it('rejects stale role changes with 409', function () {
    $bank = Bank::factory()->create();
    $target = bankStaff(bank: $bank);
    $request = AccessRequest::factory()->create(['bank_id' => $bank->id, 'membership_id' => $target->membership->id, 'membership_version' => 0, 'kind' => 'role_change', 'roles' => ['treasury_approver']]);
    $target->membership->forceFill(['version' => 1])->save();
    signInUser(User::factory()->platformAdmin()->create());

    $this->postJson(route('access.review', $request), ['decision' => 'approve'])->assertConflict();

    expect($request->fresh()->status)->toBe('pending');
    expect($target->membership->fresh()->roles)->toBe(['treasury_maker']);
});

it('preserves the last bank admin with 409', function () {
    $admin = bankStaff(BankRole::Admin);
    signInUser($admin);

    $this->deleteJson(route('access.revoke', $admin->membership))->assertConflict();

    expect($admin->membership->fresh()->active)->toBeTrue();
});

it('revokes a staff membership and its existing sessions', function () {
    $admin = bankStaff(BankRole::Admin);
    $staff = bankStaff(bank: $admin->membership->bank);
    signInUser($admin);

    $this->delete(route('access.revoke', $staff->membership))->assertRedirect();

    expect($staff->membership->fresh()->active)->toBeFalse();
    expect($staff->fresh()->auth_version)->toBe(1);
});

it('creates only the invited bank membership from an accepted token', function () {
    $token = str_repeat('a', 64);
    $invitation = BankInvitation::factory()->create(['token_hash' => hash('sha256', $token)]);

    $this->post(route('invitation.accept', $token), ['password' => 'SecureBank123!', 'password_confirmation' => 'SecureBank123!'])->assertRedirect(route('login'));

    $user = User::query()->where('email', $invitation->email)->firstOrFail();
    $this->assertDatabaseHas('bank_memberships', ['bank_id' => $invitation->bank_id, 'user_id' => $user->id, 'active' => true]);
    expect($user->is_platform_admin)->toBeFalse();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('rejects expired or previously used invitation tokens with 404', function (string $state) {
    $this->freezeTime();
    $token = str_repeat('b', 64);
    BankInvitation::factory()->create(['token_hash' => hash('sha256', $token), 'expires_at' => $state === 'expired' ? now() : now()->addDay(), 'accepted_at' => $state === 'used' ? now() : null]);

    $this->get(route('invitation.show', $token))->assertNotFound();
})->with(['expired', 'used']);

it('rejects attempts to replace invited roles or email with 422', function () {
    $token = str_repeat('c', 64);
    $invitation = BankInvitation::factory()->create(['token_hash' => hash('sha256', $token)]);

    $this->postJson(route('invitation.accept', $token), ['password' => 'SecureBank123!', 'password_confirmation' => 'SecureBank123!', 'email' => 'attacker@example.test', 'roles' => ['bank_admin'], 'is_platform_admin' => true])->assertUnprocessable()->assertJsonValidationErrors(['email', 'roles', 'is_platform_admin']);

    $this->assertDatabaseMissing('users', ['email' => $invitation->email]);
});

it('rejects undefined roles with 422', function () {
    signInUser(bankStaff(BankRole::Admin));

    $this->postJson(route('access.store'), ['name' => 'Attacker', 'email' => 'attacker@example.test', 'roles' => ['platform_admin']])->assertUnprocessable()->assertJsonValidationErrors('roles.0');

    $this->assertDatabaseCount('access_requests', 0);
});

it('rejects writes with used or expired invitation tokens with 404', function (string $state) {
    $this->freezeTime();
    $token = str_repeat('d', 64);
    $invitation = BankInvitation::factory()->create(['token_hash' => hash('sha256', $token), 'expires_at' => $state === 'expired' ? now() : now()->addDay(), 'accepted_at' => $state === 'used' ? now() : null]);

    $this->postJson(route('invitation.accept', $token), ['password' => 'SecureBank123!', 'password_confirmation' => 'SecureBank123!'])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => $invitation->email]);
})->with(['expired', 'used']);

it('rejects access changes targeting another banks membership with 404', function () {
    $target = bankStaff();
    signInUser(bankStaff(BankRole::Admin));

    $this->postJson(route('access.roles', $target->membership), ['roles' => ['bank_admin']])->assertNotFound();

    $this->assertDatabaseCount('access_requests', 0);
    expect($target->membership->fresh()->roles)->toBe(['treasury_maker']);
});

it('does not send an invitation after an access request is rejected', function () {
    $request = AccessRequest::factory()->create();
    signInUser(User::factory()->platformAdmin()->create());
    Notification::fake();

    $this->post(route('access.review', $request), ['decision' => 'reject'])->assertRedirect();

    expect($request->fresh()->status)->toBe('rejected');
    $this->assertDatabaseCount('bank_invitations', 0);
    Notification::assertNothingSent();
});

it('escapes invitation names in the email and acceptance page', function () {
    $name = '<script>alert("invitation")</script>';
    $token = str_repeat('e', 64);
    $invitation = BankInvitation::factory()->create(['name' => $name, 'token_hash' => hash('sha256', $token)]);
    $mail = (new BankInvitationNotification($invitation, $token))->toMail(new stdClass)->render();

    $this->get(route('invitation.show', $token))->assertOk()->assertSee(e($name), false)->assertDontSee($name, false);

    expect((string) $mail)->not->toContain($name);
});
