<?php

namespace App\Actions;

use App\Models\Bank;
use App\Models\SettlementInstruction;
use App\Models\User;
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
            $details[$data['channel'] === 'swift' ? 'bic' : 'rtgs_code'] = $data[$data['channel'] === 'swift' ? 'bic' : 'rtgs_code'];
            $instruction = SettlementInstruction::create([
                'bank_id' => $bank->id, 'created_by' => $actor->id,
                'version' => ((int) SettlementInstruction::query()->where('bank_id', $bank->id)->max('version')) + 1,
                'details' => $details, 'bank_profile' => SettlementInstruction::profileOf($bank),
            ]);
            $this->audit->handle($actor, 'instructions.proposed', $instruction, ['version' => $instruction->version]);

            return $instruction->refresh();
        }, attempts: 3);
    }

    public function review(User $actor, SettlementInstruction $instruction, bool $approve, ?string $reason): SettlementInstruction
    {
        Gate::forUser($actor)->authorize('view', $instruction);

        return DB::transaction(function () use ($actor, $instruction, $approve, $reason): SettlementInstruction {
            $bank = Bank::query()->lockForUpdate()->findOrFail($instruction->bank_id);
            $current = SettlementInstruction::query()->lockForUpdate()->findOrFail($instruction->id);
            $current->setRelation('bank', $bank);
            $authVersion = $actor->auth_version;
            $actor->refresh()->load('membership.bank');
            abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            Gate::forUser($actor)->authorize('review', $current);
            abort_unless($current->status === 'pending', 409, 'This instruction version has already been reviewed.');
            if ($approve) {
                abort_unless($current->matchesBankProfile(), 409, 'The bank profile has changed. Propose a fresh instruction version.');
            }
            $current->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason])->save();
            $this->audit->handle($actor, $approve ? 'instructions.approved' : 'instructions.rejected', $current, ['version' => $current->version]);

            return $current;
        }, attempts: 3);
    }
}
