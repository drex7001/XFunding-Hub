<?php

use App\Actions\ManageDealDisputes;
use App\Actions\ManageSettlementConfirmations;
use App\BankRole;
use App\Models\AuditEvent;
use App\Models\DealEvent;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Database\Seeders\DemoSettlementSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->freezeTime();
    enableDemoPayments();
});

it('notifies eligible own-bank approvers of pending confirmation without identity or private evidence', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $approver = bankStaff(BankRole::Approver, $deal->borrowerBank);
    $auditor = bankStaff(BankRole::Auditor, $deal->borrowerBank);
    $other = bankStaff(BankRole::Approver, $deal->lenderBank);
    app(ManageSettlementConfirmations::class)->propose($maker, $deal, settlementPayload());
    expect($approver->notifications()->count())->toBe(1)
        ->and($maker->notifications()->count())->toBe(0)
        ->and($auditor->notifications()->count())->toBe(0)
        ->and($other->notifications()->count())->toBe(0);
    signInUser($approver);
    $response = $this->getJson(route('api.notifications.index'))->assertOk()->assertJsonCount(1, 'data');
    expect($response->getContent())->toContain('awaits independent review')
        ->not->toContain($deal->lenderBank->name)->not->toContain('SYNTHETIC-OFFPLATFORM-REF')->not->toContain('Private institution');
    expect(DatabaseNotification::query()->value('data'))->not->toContain('SYNTHETIC-OFFPLATFORM-REF');
});

it('sends generic outcome notifications to both institutions while omitting revoked members', function () {
    $deal = unlockedDeal();
    $own = bankStaff(BankRole::Auditor, $deal->borrowerBank);
    $other = bankStaff(BankRole::Admin, $deal->lenderBank);
    $revoked = bankStaff(BankRole::Approver, $deal->lenderBank);
    $revoked->membership->update(['active' => false]);
    confirmedInstitution($deal, $deal->borrowerBank);
    expect($own->notifications()->count())->toBe(1)->and($other->notifications()->count())->toBe(1)->and($revoked->notifications()->count())->toBe(0);
    signInUser($other);
    $this->get(route('notifications.index'))->assertOk()->assertSee('Borrower settlement confirmation approved.');
});

it('rechecks current notification roles and scopes read acknowledgements to the recipient', function () {
    $deal = unlockedDeal();
    $approver = bankStaff(BankRole::Approver, $deal->borrowerBank);
    app(ManageSettlementConfirmations::class)->propose(bankStaff(BankRole::Maker, $deal->borrowerBank), $deal, settlementPayload());
    $notification = $approver->notifications()->firstOrFail();
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->postJson(route('api.notifications.read', $notification->id))->assertNotFound();
    signInUser($approver);
    $this->postJson(route('api.notifications.read', $notification->id))->assertOk()->assertJsonPath('data.read', true);
    $this->postJson(route('api.notifications.read', $notification->id))->assertOk();
    $approver->membership->update(['roles' => [BankRole::Admin->value]]);
    $this->getJson(route('api.notifications.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(route('api.notifications.read', $notification->id))->assertNotFound();
});

it('keeps pre-fee timelines anonymous and limits timeline reads to participants', function () {
    $borrower = bankStaff();
    $deal = paymentDeal($borrower);
    signInUser($borrower);
    $response = $this->getJson(route('api.deals.timeline', $deal))->assertOk()->assertJsonCount(2, 'milestones')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->not->toContain($deal->lenderBank->name)->not->toContain('DEMO000012345678');
    signInUser(bankStaff());
    $this->getJson(route('api.deals.timeline', $deal))->assertNotFound();
    signInUser(User::factory()->platformAdmin()->create());
    $this->getJson(route('api.deals.timeline', $deal))->assertNotFound();
});

it('paginates immutable timeline events with bounded query counts', function () {
    $deal = unlockedDeal();
    DealEvent::factory()->count(2)->create(['deal_id' => $deal->id]);
    signInUser(bankStaff(BankRole::Auditor, $deal->borrowerBank));
    DB::enableQueryLog();
    $this->getJson(route('api.deals.timeline', $deal))->assertOk()->assertJsonCount(2, 'data');
    $initial = count(DB::getQueryLog());
    DealEvent::factory()->count(30)->create(['deal_id' => $deal->id]);
    DB::flushQueryLog();
    $response = $this->getJson(route('api.deals.timeline', $deal))->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 32);
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($expanded)->toBeLessThanOrEqual($initial + 1);
    $this->getJson($response->json('links.next'))->assertOk()->assertJsonCount(7, 'data');
});

it('does not copy private confirmation or dispute content to timelines and audit context', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    app(ManageSettlementConfirmations::class)->propose($maker, $deal, settlementPayload());
    app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal, 'SECRET-DISPUTE synthetic settlement discrepancy.');
    signInUser(bankStaff(BankRole::Maker, $deal->lenderBank));
    $response = $this->getJson(route('api.deals.timeline', $deal))->assertOk();
    expect($response->getContent())->not->toContain('SYNTHETIC-OFFPLATFORM-REF')->not->toContain('SECRET-DISPUTE')->not->toContain($maker->name);
    expect(AuditEvent::query()->where('subject_type', $deal->getMorphClass())->pluck('context')->toJson())->not->toContain('SYNTHETIC-OFFPLATFORM-REF')->not->toContain('SECRET-DISPUTE');
});

it('prevents modification and deletion of persisted timeline events', function () {
    $event = DealEvent::factory()->create();
    expect(fn () => $event->update(['kind' => 'settled']))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
});

it('escapes private attestation and privileged dispute content in HTML', function () {
    $deal = unlockedDeal();
    $dangerous = '<script>alert("private")</script>';
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    app(ManageSettlementConfirmations::class)->propose($maker, $deal, settlementPayload());
    $record = $deal->confirmations()->firstOrFail();
    $record->update(['reference' => $dangerous, 'note' => $dangerous]);
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->get(route('deals.show', $deal))->assertOk()->assertSee(e($dangerous), false)->assertDontSee($dangerous, false);
    $dispute = app(ManageDealDisputes::class)->open(bankStaff(BankRole::Approver, $deal->borrowerBank), $deal, $dangerous);
    signInUser(User::factory()->platformAdmin()->create());
    $this->post(route('disputes.review', $dispute), ['reason' => 'Inspect synthetic evidence safely.'])->assertOk()->assertSee(e($dangerous), false)->assertDontSee($dangerous, false);
});

it('adds one synthetic pending attestation without resetting demo profiles or verification', function () {
    $deal = unlockedDeal();
    $maker = bankStaff(BankRole::Maker, $deal->borrowerBank);
    $maker->update(['email' => 'borrower.maker@xfunding.example']);
    $deal->borrowerBank->update(['name' => 'User edited demonstration bank']);
    $this->seed(DemoSettlementSeeder::class);
    $this->seed(DemoSettlementSeeder::class);
    expect($deal->confirmations()->count())->toBe(1)
        ->and($deal->borrowerBank->fresh()->name)->toBe('User edited demonstration bank')
        ->and($deal->fresh()->status->value)->toBe('unlocked');
    expect($deal->confirmations()->firstOrFail()->note)->toContain('No real interbank transfer');
});

it('refuses synthetic settlement seeding in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    expect(fn () => app(DemoSettlementSeeder::class)->run())->toThrow(RuntimeException::class);
});

it('rejects stale authenticated approvals under the transaction lock', function () {
    $deal = unlockedDeal();
    $record = app(ManageSettlementConfirmations::class)->propose(bankStaff(BankRole::Maker, $deal->borrowerBank), $deal, settlementPayload());
    $approver = bankStaff(BankRole::Approver, $deal->borrowerBank);
    User::query()->whereKey($approver->id)->increment('auth_version');
    expect(fn () => app(ManageSettlementConfirmations::class)->review($approver, $record, true, null))->toThrow(HttpException::class);
    expect($record->fresh()->status)->toBe('pending');
});

it('preserves settled proof when an additional receipt requires refund review', function () {
    $deal = unlockedDeal();
    confirmedInstitution($deal, $deal->borrowerBank);
    confirmedInstitution($deal->fresh(), $deal->lenderBank);
    $payment = $deal->fresh()->verifiedPayment;
    signedPaymentNotification(paymentNotificationPayload($payment))->assertOk()->assertJsonPath('result', 'refund_review');
    expect($deal->fresh()->hasVerifiedFee())->toBeTrue()->and($deal->fresh()->status->value)->toBe('settled');
});

it('uses a database uniqueness constraint for approved bank attestations', function () {
    $deal = unlockedDeal();
    $record = confirmedInstitution($deal, $deal->borrowerBank);
    expect(fn () => SettlementConfirmation::factory()->create([
        'deal_id' => $deal->id, 'bank_id' => $deal->borrower_bank_id, 'pending_slot' => null,
        'status' => 'approved', 'approved_slot' => $record->approved_slot,
    ]))->toThrow(QueryException::class);
});
