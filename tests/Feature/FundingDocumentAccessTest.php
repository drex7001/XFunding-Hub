<?php

use App\Actions\ManageFundingRequests;
use App\BankStatus;
use App\FundingRequestStatus;
use App\Models\BankDocument;
use App\Models\FundingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->freezeTime();
});

it('stores request documents privately and invalidates a pending publication', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    financialEvidence($record);
    app(ManageFundingRequests::class)->submit($maker, $record);
    $approval = $record->approvals()->sole();
    signInUser($maker);

    $this->post(route('funding.documents.store', $record), ['document' => UploadedFile::fake()->create('new-audit.pdf', 10, 'application/pdf')])->assertRedirect(route('funding.show', $record));

    expect($record->fresh()->status)->toBe(FundingRequestStatus::Draft);
    expect($approval->fresh()->status)->toBe('superseded');
    $document = $record->documents()->where('original_name', 'new-audit.pdf')->sole();
    Storage::disk('private')->assertExists($document->path);
});

it('lets verified lender treasury staff download only published request evidence', function () {
    Storage::fake('private');
    $record = FundingRequest::factory()->published()->create();
    $document = financialEvidence($record);
    $lender = bankStaff();
    signInUser($lender);

    $this->get(route('funding.documents.show', $document))->assertDownload('financial-report.pdf')->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseHas('audit_events', ['action' => 'funding.document_downloaded', 'actor_id' => $lender->id, 'bank_id' => $record->bank_id]);
});

it('hides other banks draft documents with 404', function () {
    Storage::fake('private');
    $document = financialEvidence(FundingRequest::factory()->create());
    signInUser(bankStaff());

    $this->getJson(route('funding.documents.show', $document))->assertNotFound();

    $this->assertDatabaseCount('audit_events', 0);
});

it('closes lender document downloads at the bidding deadline with 404', function () {
    Storage::fake('private');
    $record = FundingRequest::factory()->published()->create(['bidding_deadline' => now()]);
    $document = financialEvidence($record);
    signInUser(bankStaff());

    $this->getJson(route('funding.documents.show', $document))->assertNotFound();
});

it('prevents published document deletion or additions with 403', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker, ['status' => FundingRequestStatus::Open]);
    $document = financialEvidence($record);
    signInUser($maker);

    $this->deleteJson(route('funding.documents.destroy', $document))->assertForbidden();
    $this->postJson(route('funding.documents.store', $record), ['document' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')])->assertForbidden();

    $this->assertModelExists($document);
    Storage::disk('private')->assertExists($document->path);
});

it('rejects financial uploads of unsupported type or size with 422', function (string $name, int $size, string $mime) {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    signInUser($maker);

    $this->postJson(route('funding.documents.store', $record), ['document' => UploadedFile::fake()->create($name, $size, $mime)])->assertUnprocessable()->assertJsonValidationErrors('document');

    $this->assertDatabaseCount('funding_documents', 0);
})->with(['wrong type' => ['report.html', 10, 'text/html'], 'too large' => ['report.pdf', 10241, 'application/pdf']]);

it('keeps onboarding documents inaccessible to lender users', function () {
    Storage::fake('private');
    $document = BankDocument::factory()->create();
    Storage::disk('private')->put($document->path, 'confidential onboarding evidence');
    signInUser(bankStaff());

    $this->getJson(route('documents.show', $document))->assertNotFound();
});

it('keeps owner detail queries bounded as the financial document set grows', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    financialEvidence($record);
    financialEvidence($record);
    signInUser($maker);
    DB::enableQueryLog();
    $this->get(route('funding.show', $record))->assertOk();
    $initial = count(DB::getQueryLog());
    for ($index = 0; $index < 8; $index++) {
        financialEvidence($record);
    }
    DB::flushQueryLog();

    $this->get(route('funding.show', $record))->assertOk();
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expanded)->toBeLessThanOrEqual($initial + 2);
});

it('removes an editable draft document and supersedes its old approval', function () {
    Storage::fake('private');
    $maker = bankStaff();
    $record = fundingDraft($maker);
    $document = financialEvidence($record);
    app(ManageFundingRequests::class)->submit($maker, $record);
    $approval = $record->approvals()->sole();
    signInUser($maker);

    $this->delete(route('funding.documents.destroy', $document))->assertRedirect(route('funding.show', $record));

    $this->assertModelMissing($document);
    expect($record->fresh()->status)->toBe(FundingRequestStatus::Draft);
    expect($approval->fresh()->status)->toBe('superseded');
    Storage::disk('private')->assertMissing($document->path);
});

it('stops financial downloads when the borrower loses verification with 404', function () {
    Storage::fake('private');
    $record = FundingRequest::factory()->published()->create();
    $document = financialEvidence($record);
    $record->bank->forceFill(['status' => BankStatus::Pending])->save();
    signInUser(bankStaff());

    $this->getJson(route('funding.documents.show', $document))->assertNotFound();

    $this->assertDatabaseCount('audit_events', 0);
});
