<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $code)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('api.mail.verify_subject'))
            ->greeting(__('api.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('api.mail.verify_code', ['code' => $this->code]))
            ->line(__('api.mail.verify_expiry', ['minutes' => config('emtedad.auth.verification_minutes')]))
            ->line(__('api.mail.ignore'));
    }
}
