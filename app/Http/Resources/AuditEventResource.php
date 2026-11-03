<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AuditEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'action' => $this->action, 'label' => ucfirst(str_replace(['.', '_'], ' ', $this->action)),
            'actor' => $this->actorLabel(), 'subject' => Str::headline(class_basename($this->subject_type)), 'subjectId' => $this->subject_id,
            'at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * Staff names appear only within their own institution, so rows recorded
     * against another bank before attribution was corrected stay anonymous.
     */
    private function actorLabel(): string
    {
        $actor = $this->actor;

        return match (true) {
            $actor === null => 'System',
            $actor->is_platform_admin => 'Platform operations',
            $actor->membership?->bank_id === $this->bank_id => $actor->name,
            default => 'Another institution',
        };
    }
}
