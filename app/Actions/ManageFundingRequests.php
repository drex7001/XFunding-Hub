<?php

namespace App\Actions;

use App\FundingRequestStatus;
use App\Models\Bank;
use App\Models\FundingDocument;
use App\Models\FundingRequest;
use App\Models\FundingRequestApproval;
use App\Models\User;
use App\OfferStatus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ManageFundingRequests
{
    public function __construct(private RecordAuditEvent $audit, private ManageOffers $offers) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): FundingRequest
    {
        Gate::forUser($actor)->authorize('create', FundingRequest::class);

        return DB::transaction(function () use ($actor, $data): FundingRequest {
            Bank::query()->lockForUpdate()->findOrFail($actor->membership->bank_id);
            $authVersion = $actor->auth_version;
            $actor->refresh()->load('membership.bank');
            abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            Gate::forUser($actor)->authorize('create', FundingRequest::class);
            $record = new FundingRequest($this->terms($data) + ['bank_id' => $actor->membership->bank_id, 'created_by' => $actor->id]);
            $record->forceFill(['contributor_ids' => [$actor->id], 'version' => 1, 'status' => FundingRequestStatus::Draft])->save();
            $this->audit->handle($actor, 'funding.created', $record);

            return $record->refresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, FundingRequest $record, array $data): FundingRequest
    {
        return $this->locked($actor, $record, function (FundingRequest $current) use ($actor, $data): FundingRequest {
            Gate::forUser($actor)->authorize('update', $current);
            $current->fill($this->terms($data));
            $this->reviseDraft($actor, $current);
            $this->audit->handle($actor, 'funding.updated', $current);

            return $current;
        });
    }

    public function addDocument(User $actor, FundingRequest $record, UploadedFile $file): FundingDocument
    {
        Gate::forUser($actor)->authorize('create', [FundingDocument::class, $record]);
        $sha256 = hash_file('sha256', $file->getRealPath());
        $path = $file->store('funding/'.$record->id, 'private');
        try {
            return $this->locked($actor, $record, function (FundingRequest $current) use ($actor, $file, $path, $sha256): FundingDocument {
                Gate::forUser($actor)->authorize('create', [FundingDocument::class, $current]);
                $document = $current->documents()->create([
                    'uploaded_by' => $actor->id, 'original_name' => $file->getClientOriginalName(),
                    'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $sha256,
                ]);
                $this->reviseDraft($actor, $current);
                $this->audit->handle($actor, 'funding.document_uploaded', $current, ['document_id' => $document->id]);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    public function removeDocument(User $actor, FundingDocument $document): void
    {
        $this->locked($actor, $document->fundingRequest, function (FundingRequest $current) use ($actor, $document): void {
            $document = $current->documents()->findOrFail($document->id);
            $document->setRelation('fundingRequest', $current);
            Gate::forUser($actor)->authorize('delete', $document);
            $path = $document->path;
            $document->delete();
            $this->reviseDraft($actor, $current);
            $this->audit->handle($actor, 'funding.document_removed', $current, ['document_id' => $document->id]);
            DB::afterCommit(fn () => Storage::disk('private')->delete($path));
        });
    }

    public function submit(User $actor, FundingRequest $record): FundingRequest
    {
        return $this->locked($actor, $record, function (FundingRequest $current) use ($actor): FundingRequest {
            Gate::forUser($actor)->authorize('submit', $current);
            abort_unless($current->status === FundingRequestStatus::Draft && $current->pendingApproval === null, 409, 'Only a draft without a pending approval can be submitted.');
            $this->ensurePublishable($current);
            $current->approvals()->create(['proposed_by' => $actor->id, 'expected_version' => $current->version, 'kind' => 'publish', 'bank_profile' => $this->bankProfile($current->bank)]);
            $current->forceFill(['status' => FundingRequestStatus::PendingApproval])->save();
            $this->audit->handle($actor, 'funding.publication_requested', $current);

            return $current;
        });
    }

    public function proposeCancellation(User $actor, FundingRequest $record, string $reason): FundingRequest
    {
        return $this->locked($actor, $record, function (FundingRequest $current) use ($actor, $reason): FundingRequest {
            Gate::forUser($actor)->authorize('proposeCancellation', $current);
            abort_unless(in_array($current->currentStatus(), [FundingRequestStatus::Draft, FundingRequestStatus::Open], true) && $current->pendingApproval === null && $current->pendingSelection === null, 409, 'Resolve any pending selection before requesting cancellation.');
            $current->approvals()->create(['proposed_by' => $actor->id, 'expected_version' => $current->version, 'kind' => 'cancel', 'proposal_reason' => $reason]);
            $this->audit->handle($actor, 'funding.cancellation_requested', $current);

            return $current;
        });
    }

    public function review(User $actor, FundingRequest $record, FundingRequestApproval $approval, bool $approve, ?string $reason): FundingRequest
    {
        return $this->locked($actor, $record, function (FundingRequest $current) use ($actor, $approval, $approve, $reason): FundingRequest {
            abort_unless($approval->funding_request_id === $current->id, 404);
            $pending = $current->pendingApproval;
            abort_unless($pending !== null && $pending->id === $approval->id, 409, 'This approval is no longer pending.');
            Gate::forUser($actor)->authorize('review', $current);
            abort_unless($pending->expected_version === $current->version, 409, 'The request has changed. Submit it for approval again.');
            abort_unless($current->currentStatus() !== FundingRequestStatus::Expired, 409, 'The bidding deadline has passed.');
            if ($pending->kind === 'publish') {
                abort_unless($current->status === FundingRequestStatus::PendingApproval, 409);
                if ($approve) {
                    $expectedProfile = $pending->bank_profile;
                    $currentProfile = $this->bankProfile($current->bank);
                    abort_unless(is_array($expectedProfile), 409, 'Submit a new approval with the current bank profile.');
                    ksort($expectedProfile);
                    ksort($currentProfile);
                    abort_unless($expectedProfile === $currentProfile, 409, 'The bank profile has changed. Edit the draft and submit a new approval.');
                    $this->ensurePublishable($current);
                    $bank = $current->bank;
                    $current->forceFill([
                        'status' => FundingRequestStatus::Open, 'published_at' => now(),
                        'borrower_profile' => ['name' => $bank->name, 'country_code' => $bank->country_code, 'registration_number' => $bank->registration_number, 'background' => $current->borrower_background],
                    ])->save();
                } else {
                    $current->forceFill(['status' => FundingRequestStatus::Draft])->save();
                }
            } else {
                abort_unless(in_array($current->status, [FundingRequestStatus::Draft, FundingRequestStatus::Open], true), 409);
                if ($approve) {
                    $current->forceFill(['status' => FundingRequestStatus::Cancelled, 'cancelled_at' => now()])->save();
                    $this->offers->closeForRequest($current, OfferStatus::Unsuccessful);
                }
            }
            $pending->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason])->save();
            $this->audit->handle($actor, $approve ? ($pending->kind === 'publish' ? 'funding.published' : 'funding.cancelled') : 'funding.approval_rejected', $current, ['approval_id' => $pending->id, 'kind' => $pending->kind]);

            return $current;
        });
    }

    public function expire(FundingRequest $record): bool
    {
        return $this->locked(null, $record, function (FundingRequest $current): bool {
            if (! in_array($current->status, [FundingRequestStatus::Open, FundingRequestStatus::PendingApproval], true) || $current->bidding_deadline->isFuture()) {
                return false;
            }
            $current->approvals()->where('status', 'pending')->update(['status' => 'superseded']);
            $current->forceFill(['status' => FundingRequestStatus::Expired])->save();
            $this->offers->closeForRequest($current, OfferStatus::Expired);
            $this->audit->handle(null, 'funding.expired', $current, ['reason' => 'bidding_deadline']);

            return true;
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function terms(array $data): array
    {
        return Arr::only($data, ['title', 'currency', 'tenor_days', 'settlement_date', 'day_count', 'borrower_background'])
            + ['amount_minor' => FundingRequest::amountToMinor($data['amount']), 'rate_basis' => config('marketplace.rate_basis'), 'bidding_deadline' => CarbonImmutable::parse($data['bidding_deadline'], 'UTC')->utc()];
    }

    private function reviseDraft(User $actor, FundingRequest $current): void
    {
        $current->approvals()->where('status', 'pending')->update(['status' => 'superseded']);
        $current->forceFill([
            'status' => FundingRequestStatus::Draft, 'version' => $current->version + 1,
            'contributor_ids' => array_values(array_unique([...$current->contributor_ids, $actor->id])),
        ])->save();
        $current->setRelation('pendingApproval', null);
    }

    /** @return array<string, string> */
    private function bankProfile(Bank $bank): array
    {
        return ['name' => $bank->name, 'country_code' => $bank->country_code, 'registration_number' => $bank->registration_number];
    }

    private function ensurePublishable(FundingRequest $current): void
    {
        if (! $current->bidding_deadline->isFuture() || $current->settlement_date->isBefore(today()) || $current->bidding_deadline->toDateString() > $current->settlement_date->toDateString()) {
            throw ValidationException::withMessages(['bidding_deadline' => 'Choose a future bidding deadline on or before the settlement date.']);
        }
        if (! in_array($current->currency, config('marketplace.currencies'), true) || ! in_array($current->day_count, config('marketplace.day_counts'), true)) {
            throw ValidationException::withMessages(['currency' => 'Review the request currency and day-count convention before submission.']);
        }
        if ($current->documents->isEmpty() || $current->documents->contains(fn (FundingDocument $document): bool => ! Storage::disk('private')->exists($document->path))) {
            throw ValidationException::withMessages(['documents' => 'Upload at least one available financial PDF before submitting for approval.']);
        }
    }

    /** @param Closure(FundingRequest): mixed $callback */
    private function locked(?User $actor, FundingRequest $record, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $record, $callback): mixed {
            $bank = Bank::query()->lockForUpdate()->findOrFail($record->bank_id);
            $current = FundingRequest::query()->lockForUpdate()->findOrFail($record->id);
            $current->setRelation('bank', $bank)->load(['documents', 'pendingApproval']);
            if ($actor !== null) {
                $authVersion = $actor->auth_version;
                $actor->refresh()->load('membership.bank');
                abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            }

            return $callback($current);
        }, attempts: 3);
    }
}
