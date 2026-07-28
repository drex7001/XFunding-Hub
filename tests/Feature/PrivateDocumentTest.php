<?php

use App\BankRole;
use App\Models\AuditEvent;
use App\Models\Bank;
use App\Models\BankDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('stores onboarding PDFs privately and audits their upload', function () {
    Storage::fake('private');
    $admin = bankStaff(BankRole::Admin, Bank::factory()->create());
    signInUser($admin);
    $file = UploadedFile::fake()->create('audit.pdf', 10, 'application/pdf');

    $this->post(route('documents.store', $admin->membership->bank), ['document' => $file])->assertRedirect();

    $document = BankDocument::query()->sole();
    Storage::disk('private')->assertExists($document->path);
    $this->assertDatabaseHas('audit_events', ['action' => 'document.uploaded', 'bank_id' => $admin->membership->bank_id]);
});

it('rejects invalid or oversized onboarding documents with 422', function (string $name, int $size, string $mime) {
    Storage::fake('private');
    $admin = bankStaff(BankRole::Admin, Bank::factory()->create());
    signInUser($admin);

    $this->postJson(route('documents.store', $admin->membership->bank), ['document' => UploadedFile::fake()->create($name, $size, $mime)])->assertUnprocessable()->assertJsonValidationErrors('document');

    $this->assertDatabaseCount('bank_documents', 0);
    Storage::disk('private')->assertDirectoryEmpty('/');
})->with(['wrong type' => ['report.html', 10, 'text/html'], 'too large' => ['report.pdf', 10241, 'application/pdf']]);

it('downloads an owning banks document without caching it publicly', function () {
    Storage::fake('private');
    $user = bankStaff(BankRole::Auditor);
    $document = BankDocument::factory()->create(['bank_id' => $user->membership->bank_id]);
    Storage::disk('private')->put($document->path, 'private document');
    signInUser($user);

    $this->get(route('documents.show', $document))->assertDownload('audit-report.pdf')->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseHas('audit_events', ['action' => 'document.downloaded', 'actor_id' => $user->id]);
});

it('hides other bank documents with 404', function () {
    Storage::fake('private');
    $document = BankDocument::factory()->create();
    Storage::disk('private')->put($document->path, 'private document');
    signInUser(bankStaff());

    $this->getJson(route('documents.show', $document))->assertNotFound();

    $this->assertDatabaseCount('audit_events', 0);
});

it('requires platform staff to state a reason before document access with 422', function () {
    $document = BankDocument::factory()->create();
    signInUser(User::factory()->platformAdmin()->create());

    $this->getJson(route('documents.show', $document))->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->assertDatabaseCount('audit_events', 0);
});

it('records a platform reviewers document access reason', function () {
    Storage::fake('private');
    $document = BankDocument::factory()->create();
    Storage::disk('private')->put($document->path, 'private document');
    signInUser(User::factory()->platformAdmin()->create());

    $this->get(route('documents.show', ['document' => $document, 'reason' => 'Review onboarding evidence']))->assertDownload();

    $event = AuditEvent::query()->sole();
    expect($event->context)->toBe(['reason' => 'Review onboarding evidence']);
});

it('freezes onboarding document uploads after verification with 403', function () {
    Storage::fake('private');
    $admin = bankStaff(BankRole::Admin);
    signInUser($admin);

    $this->postJson(route('documents.store', $admin->membership->bank), ['document' => UploadedFile::fake()->create('audit.pdf', 10, 'application/pdf')])->assertForbidden();

    $this->assertDatabaseCount('bank_documents', 0);
});
