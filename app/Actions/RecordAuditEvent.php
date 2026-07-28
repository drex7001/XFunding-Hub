<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAuditEvent
{
    /** @param array<string, mixed> $context */
    public function handle(?User $actor, string $action, Model $subject, array $context = []): AuditEvent
    {
        return AuditEvent::create([
            'actor_id' => $actor?->id,
            'bank_id' => $subject instanceof Bank ? $subject->id : ($subject->bank_id ?? $actor?->membership?->bank_id),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'context' => $context,
        ]);
    }
}
