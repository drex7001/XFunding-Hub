<?php

namespace App\Actions;

use App\BankStatus;
use App\FundingRequestStatus;
use App\Models\Bank;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\OfferApproval;
use App\Models\SelectionProposal;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageOffers
{
    public function __construct(private RecordAuditEvent $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, FundingRequest $request, array $data): Offer
    {
        Gate::forUser($actor)->authorize('create', [Offer::class, $request]);

        return $this->withRequestLock($actor, $request, $actor->membership->bank_id, function (FundingRequest $current) use ($actor, $data): Offer {
            Gate::forUser($actor)->authorize('create', [Offer::class, $current]);
            $this->ensureOpen($current);
            $this->instruction($actor->membership->bank_id, $data['settlement_instruction_id']);
            $offer = new Offer($this->terms($data) + ['funding_request_id' => $current->id, 'bank_id' => $actor->membership->bank_id, 'created_by' => $actor->id]);
            $offer->forceFill(['status' => OfferStatus::Draft, 'contributor_ids' => [$actor->id], 'version' => 1])->save();
            $this->audit->handle($actor, 'offer.created', $offer);

            return $offer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Offer $offer, array $data): Offer
    {
        return $this->withRequestLock($actor, $offer->fundingRequest, $offer->bank_id, function (FundingRequest $request) use ($actor, $offer, $data): Offer {
            $current = $this->lockedOffer($offer, $request);
            Gate::forUser($actor)->authorize('update', $current);
            $this->instruction($current->bank_id, $data['settlement_instruction_id']);
            $current->fill($this->terms($data));
            OfferApproval::query()->where('offer_id', $current->id)->where('status', 'pending')->update(['status' => 'superseded']);
            $current->forceFill(['status' => OfferStatus::Draft, 'version' => $current->version + 1, 'contributor_ids' => array_values(array_unique([...$current->contributor_ids, $actor->id]))])->save();
            $this->audit->handle($actor, 'offer.updated', $current);

            return $current->refresh();
        });
    }

    public function submit(User $actor, Offer $offer): Offer
    {
        return $this->withRequestLock($actor, $offer->fundingRequest, $offer->bank_id, function (FundingRequest $request) use ($actor, $offer): Offer {
            $current = $this->lockedOffer($offer, $request);
            Gate::forUser($actor)->authorize('submit', $current);
            abort_unless($current->status === OfferStatus::Draft && $current->pendingApproval === null, 409, 'Only a draft offer can be submitted.');
            $this->ensureOfferValid($current);
            OfferApproval::create(['offer_id' => $current->id, 'proposed_by' => $actor->id, 'expected_version' => $current->version]);
            $current->forceFill(['status' => OfferStatus::PendingApproval])->save();
            $this->audit->handle($actor, 'offer.approval_requested', $current);

            return $current->refresh();
        });
    }

    public function review(User $actor, Offer $offer, OfferApproval $approval, bool $approve, ?string $reason): Offer
    {
        return $this->withRequestLock($actor, $offer->fundingRequest, $offer->bank_id, function (FundingRequest $request) use ($actor, $offer, $approval, $approve, $reason): Offer {
            $current = $this->lockedOffer($offer, $request);
            $pending = $current->pendingApproval;
            abort_unless($approval->offer_id === $current->id, 404);
            abort_unless($pending !== null && $pending->id === $approval->id, 409, 'This offer approval is no longer pending.');
            Gate::forUser($actor)->authorize('review', $current);
            abort_unless($pending->expected_version === $current->version && $current->status === OfferStatus::PendingApproval, 409, 'The offer has changed. Submit a fresh approval.');
            $this->ensureOpen($request);
            if ($approve) {
                $this->ensureOfferValid($current);
                abort_if(Offer::query()->where('funding_request_id', $request->id)->where('active_slot', $current->bank_id)->exists(), 409, 'Your bank already has an active offer. Withdraw it before approving a replacement.');
                $label = ((int) $request->offers()->max('label_number')) + 1;
                $current->forceFill(['status' => OfferStatus::Active, 'active_slot' => $current->bank_id, 'label_number' => $label, 'activated_at' => now()])->save();
            } else {
                $current->forceFill(['status' => OfferStatus::Draft])->save();
            }
            $pending->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reason' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            $this->audit->handle($actor, $approve ? 'offer.activated' : 'offer.rejected', $current);

            return $current->refresh();
        });
    }

    public function withdraw(User $actor, Offer $offer, string $reason): Offer
    {
        return $this->withRequestLock($actor, $offer->fundingRequest, $offer->bank_id, function (FundingRequest $request) use ($actor, $offer, $reason): Offer {
            $current = $this->lockedOffer($offer, $request);
            Gate::forUser($actor)->authorize('withdraw', $current);
            abort_unless(in_array($current->status, [OfferStatus::Draft, OfferStatus::PendingApproval, OfferStatus::Active], true) && $request->currentStatus() === FundingRequestStatus::Open, 409, 'This offer is closed or reserved by a selection.');
            $this->closeOffer($current, OfferStatus::Withdrawn);
            $this->audit->handle($actor, 'offer.withdrawn', $current, ['reason' => $reason]);

            return $current->refresh();
        });
    }

    public function expire(Offer $offer): bool
    {
        return $this->withRequestLock(null, $offer->fundingRequest, $offer->bank_id, function (FundingRequest $request) use ($offer): bool {
            $current = $this->lockedOffer($offer, $request);
            if (! in_array($current->status, [OfferStatus::Active, OfferStatus::PendingApproval], true) || $current->valid_until->isFuture()) {
                return false;
            }
            $this->closeOffer($current, OfferStatus::Expired);
            $this->audit->handle(null, 'offer.expired', $current);

            return true;
        });
    }

    public function ensureOpen(FundingRequest $request): void
    {
        abort_unless($request->currentStatus() === FundingRequestStatus::Open && $request->bank->status === BankStatus::Verified && $request->bank->can_borrow, 409, 'The request is no longer open for offers or selection.');
    }

    public function ensureOfferValid(Offer $offer): void
    {
        $this->ensureOpen($offer->fundingRequest);
        abort_unless($offer->bank->status === BankStatus::Verified && $offer->bank->can_lend && $offer->bank_id !== $offer->fundingRequest->bank_id, 409, 'The lender is no longer eligible.');
        abort_unless($offer->valid_until->isFuture() && ! $offer->valid_until->isBefore($offer->fundingRequest->bidding_deadline) && $offer->valid_until->toDateString() <= $offer->fundingRequest->settlement_date->toDateString(), 409, 'Review the offer validity before submission.');
        $this->instruction($offer->bank_id, $offer->settlement_instruction_id);
    }

    public function closeForRequest(FundingRequest $request, OfferStatus $status, ?string $winner = null): void
    {
        $ids = Offer::query()->where('funding_request_id', $request->id)->whereIn('status', [OfferStatus::Draft, OfferStatus::PendingApproval, OfferStatus::Active]);
        if ($winner !== null) {
            $ids->where('id', '!=', $winner);
        }
        OfferApproval::query()->whereIn('offer_id', (clone $ids)->select('id'))->where('status', 'pending')->update(['status' => 'superseded']);
        $ids->update(['status' => $status->value, 'active_slot' => null, 'updated_at' => now()]);
        SelectionProposal::query()->where('funding_request_id', $request->id)->where('status', 'pending')->update(['status' => 'superseded', 'pending_slot' => null, 'updated_at' => now()]);
    }

    /** @param Closure(FundingRequest): mixed $callback */
    public function withRequestLock(?User $actor, FundingRequest $request, int $lenderBankId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $request, $lenderBankId, $callback): mixed {
            $banks = Bank::query()->whereIn('id', [$request->bank_id, $lenderBankId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = FundingRequest::query()->lockForUpdate()->findOrFail($request->id);
            $current->setRelation('bank', $banks->get($current->bank_id));
            if ($actor !== null) {
                $authVersion = $actor->auth_version;
                $actor->refresh()->load('membership.bank');
                abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            }

            return $callback($current);
        }, attempts: 3);
    }

    public function lockedOffer(Offer $offer, FundingRequest $request): Offer
    {
        $current = Offer::query()->where('funding_request_id', $request->id)->lockForUpdate()->findOrFail($offer->id);
        $current->setRelation('fundingRequest', $request)->load(['bank', 'settlementInstruction.bank', 'pendingApproval']);

        return $current;
    }

    private function instruction(int $bankId, string $id): SettlementInstruction
    {
        $instruction = SettlementInstruction::query()->where('bank_id', $bankId)->where('status', 'approved')->with('bank')->find($id);
        if ($instruction === null || ! $instruction->matchesBankProfile()) {
            throw ValidationException::withMessages(['settlement_instruction_id' => 'Choose an independently approved instruction version for your current bank profile.']);
        }

        return $instruction;
    }

    private function closeOffer(Offer $offer, OfferStatus $status): void
    {
        $offer->forceFill(['status' => $status, 'active_slot' => null])->save();
        OfferApproval::query()->where('offer_id', $offer->id)->where('status', 'pending')->update(['status' => 'superseded']);
        SelectionProposal::query()->where('offer_id', $offer->id)->where('status', 'pending')->update(['status' => 'superseded', 'pending_slot' => null]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function terms(array $data): array
    {
        return Arr::only($data, ['collateral', 'interest_payment', 'settlement_instruction_id'])
            + ['rate_units' => Offer::rateToUnits($data['rate']), 'valid_until' => CarbonImmutable::parse($data['valid_until'], 'UTC')->utc()];
    }
}
