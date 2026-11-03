<?php

use App\Actions\RecordAuditEvent;
use App\BankRole;
use App\Models\AuditEvent;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('limits the audit history to the bank admin, auditor and platform operations', function (BankRole $role) {
    $bank = Bank::factory()->verified()->create();
    signInUser(bankStaff($role, $bank));

    $this->getJson(route('api.banks.audit', $bank))
        ->assertStatus(in_array($role, [BankRole::Admin, BankRole::Auditor], true) ? 200 : 403);
})->with(BankRole::cases());

it('hides another bank audit history and allows platform operations', function () {
    $bank = Bank::factory()->verified()->create();
    signInUser(bankStaff(BankRole::Auditor));
    $this->getJson(route('api.banks.audit', $bank))->assertNotFound();

    signInUser(User::factory()->platformAdmin()->create());
    $this->getJson(route('api.banks.audit', $bank))->assertOk();
});

it('names own staff while keeping other institutions anonymous and private context out', function () {
    $bank = Bank::factory()->verified()->create();
    $auditor = bankStaff(BankRole::Auditor, $bank);
    $colleague = bankStaff(BankRole::Maker, $bank);
    $outsider = bankStaff(BankRole::Maker, Bank::factory()->verified()->create(['name' => 'Hidden Lender Bank']));
    app(RecordAuditEvent::class)->handle($colleague, 'funding.created', $bank, ['reason' => 'PRIVATE-CONTEXT']);
    app(RecordAuditEvent::class)->handle(null, 'funding.expired', $bank);
    app(RecordAuditEvent::class)
        ->handle(User::factory()->platformAdmin()->create(), 'bank.verified', $bank, ['reason' => 'PRIVATE-CONTEXT']);
    AuditEvent::query()
        ->create([
            'bank_id' => $bank->id,
            'actor_id' => $outsider->id,
            'action' => 'funding.document_downloaded',
            'subject_type' => Bank::class,
            'subject_id' => (string) $bank->id,
            'context' => [],
        ]);
    signInUser($auditor);

    $response = $this->getJson(route('api.banks.audit', $bank))->assertOk()->assertJsonCount(4, 'data');

    expect(collect($response->json('data'))->pluck('actor')->all())
        ->toBe(['Another institution', 'Platform operations', 'System', $colleague->name])
        ->and(
            $response->getContent()
        )->not->toContain('PRIVATE-CONTEXT')->not->toContain($outsider->name)->not->toContain('Hidden Lender Bank');
    $this->get(route('banks.audit', $bank))
        ->assertOk()
        ->assertSee('Funding created')
        ->assertSee($colleague->name)
        ->assertDontSee($outsider->name);
    $this->get(route('banks.show', $bank))->assertOk()->assertSee(route('banks.audit', $bank));
});

it('paginates the audit history without loading actors per row', function () {
    $bank = Bank::factory()->verified()->create();
    $admin = bankStaff(BankRole::Admin, $bank);
    foreach (range(1, 30) as $index) {
        app(RecordAuditEvent::class)->handle(bankStaff(BankRole::Maker, $bank), 'funding.updated', $bank);
    }
    signInUser($admin);
    DB::enableQueryLog();

    $this->getJson(route('api.banks.audit', $bank))
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.total', 30);

    expect(count(DB::getQueryLog()))->toBeLessThan(15);
});
