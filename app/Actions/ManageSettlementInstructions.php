<?php

namespace App\Actions;

use App\ApprovalStatus;
use App\DealStatus;
use App\Models\Bank;
use App\Models\Deal;
use App\Models\DealInstructionChange;
use App\Models\Offer;
use App\Models\SelectionProposal;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use App\SettlementInstructionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ManageSettlementInstructions
{
    public function __construct(private RecordAuditEvent $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): SettlementInstruction
    {
        Gate::forUser($actor)->authorize('create', SettlementInstruction::class);

        return DB::transaction(function () use ($actor, $data): SettlementInstruction {
            $bank = Bank::query()->lockForUpdate()->findOrFail($actor->membership->bank_id);
            $authVersion = $actor->auth_version;
            $actor->refresh()->load('membership.bank');
            abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            Gate::forUser($actor)->authorize('create', SettlementInstruction::class);
            $details = Arr::only($data, ['channel', 'account_name', 'account_number', 'contact_name', 'contact_email']);
            $routingField = $data['channel'] === 'swift' ? 'bic' : 'rtgs_code';
            $details[$routingField] = $data[$routingField];
            $instruction = SettlementInstruction::create([
                'bank_id' => $bank->id, 'created_by' => $actor->id,
                'version' => ((int) SettlementInstruction::query()->where('bank_id', $bank->id)->max('version')) + 1,
                'details' => $details, 'bank_profile' => SettlementInstruction::profileOf($bank),
            ]);
            $this->audit->handle($actor, 'instructions.proposed', $instruction, ['version' => $instruction->version]);

            return $instruction->refresh();
        }, attempts: 3);
    }

    public function review(
        User $actor,
        SettlementInstruction $instruction,
        bool $approve,
        ?string $reason,
    ): SettlementInstruction {
        Gate::forUser($actor)->authorize('view', $instruction);

        return DB::transaction(function () use ($actor, $instruction, $approve, $reason): SettlementInstruction {
            $bank = Bank::query()->lockForUpdate()->findOrFail($instruction->bank_id);
            $current = SettlementInstruction::query()->lockForUpdate()->findOrFail($instruction->id);
            $current->setRelation('bank', $bank);
            $authVersion = $actor->auth_version;
            $actor->refresh()->load('membership.bank');
            abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            Gate::forUser($actor)->authorize('review', $current);
            abort_unless(
                $current->status === SettlementInstructionStatus::Pending,
                409,
                'This instruction version has already been reviewed.',
            );
            if ($approve) {
                abort_unless(
                    $current->matchesBankProfile(),
                    409,
                    'The bank profile has changed. Propose a fresh instruction version.',
                );
            }
            $current->forceFill([
                'status' => $approve ? SettlementInstructionStatus::Approved : SettlementInstructionStatus::Rejected,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'reason' => $reason,
            ])->save();
            $this->audit->handle($actor, $approve ? 'instructions.approved' : 'instructions.rejected', $current, [
                'version' => $current->version,
            ]);

            return $current;
        }, attempts: 3);
    }

    /**
     * Retires an outdated approved version so it can no longer be attached to new work.
     * Open work keeps its version; deals lock this bank row before reading their instructions.
     */
    public function retire(User $actor, SettlementInstruction $instruction, string $reason): SettlementInstruction
    {
        Gate::forUser($actor)->authorize('view', $instruction);

        return DB::transaction(function () use ($actor, $instruction, $reason): SettlementInstruction {
            $bank = Bank::query()->lockForUpdate()->findOrFail($instruction->bank_id);
            $current = SettlementInstruction::query()->lockForUpdate()->findOrFail($instruction->id);
            $current->setRelation('bank', $bank);
            $authVersion = $actor->auth_version;
            $actor->refresh()->load('membership.bank');
            abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            Gate::forUser($actor)->authorize('retire', $current);
            abort_unless(
                $current->status === SettlementInstructionStatus::Approved,
                409,
                'Only an approved instruction version can be retired.',
            );
            abort_if(
                $this->isInUse($current),
                409,
                'This version is still attached to an active offer, pending selection or open deal.',
            );
            $current->forceFill([
                'status' => SettlementInstructionStatus::Retired,
                'retired_by' => $actor->id,
                'retired_at' => now(),
            ])->save();
            $this->audit->handle($actor, 'instructions.retired', $current, [
                'version' => $current->version,
                'reason' => $reason,
            ]);

            return $current;
        }, attempts: 3);
    }

    private function isInUse(SettlementInstruction $instruction): bool
    {
        return Offer::query()
            ->where('settlement_instruction_id', $instruction->id)
            ->whereIn('status', [OfferStatus::Draft, OfferStatus::PendingApproval, OfferStatus::Active])
            ->exists()
            || SelectionProposal::query()
                ->where('borrower_settlement_instruction_id', $instruction->id)
                ->where('status', ApprovalStatus::Pending)
                ->exists()
            || DealInstructionChange::query()
                ->where('settlement_instruction_id', $instruction->id)
                ->where('status', ApprovalStatus::Pending)
                ->exists()
            || Deal::query()->whereIn('status', [DealStatus::AwaitingFee, DealStatus::Unlocked, DealStatus::Disputed])
                ->where(
                    fn (Builder $query): Builder => $query->where('settlement_instruction_id', $instruction->id)
                        ->orWhere('borrower_settlement_instruction_id', $instruction->id)
                )
                ->exists();
    }
}
