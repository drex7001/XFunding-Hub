<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class DealActivityNotification extends Notification
{
    /** @param array<int, string> $roles */
    public function __construct(public string $dealId, public int $bankId, public string $kind, public ?string $side, public array $roles) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['dealId' => $this->dealId, 'bankId' => $this->bankId, 'kind' => $this->kind, 'side' => $this->side, 'roles' => $this->roles];
    }
}
