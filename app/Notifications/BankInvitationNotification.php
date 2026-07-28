<?php

namespace App\Notifications;

use App\Models\BankInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BankInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public BankInvitation $invitation, private string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your XFunding Hub bank invitation')
            ->greeting('Hello '.$this->invitation->name)
            ->line('You have been invited to join '.$this->invitation->bank->name.'.')
            ->action('Accept invitation', route('invitation.show', $this->token))
            ->line('This invitation expires in three days. If you were not expecting it, you can ignore this email.');
    }
}
