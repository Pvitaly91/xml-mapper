<?php

namespace App\Notifications;

use App\Models\OpsAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OpsAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OpsAlert $alert,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ((bool) config('feed_mediator.hypercare.alerts.mail_enabled', false)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'ops.alert.'.$this->alert->state,
            'alert_id' => $this->alert->id,
            'source' => $this->alert->source,
            'state' => $this->alert->state,
            'severity' => $this->alert->severity,
            'title' => $this->alert->title,
            'message' => $this->alert->message,
            'reason' => $this->alert->reason,
            'note' => $this->alert->note,
            'context' => $this->alert->context ?? [],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Hypercare '.$this->alert->severity.'] '.$this->alert->title)
            ->line($this->alert->message)
            ->line('State: '.$this->alert->state)
            ->when(filled($this->alert->reason), fn (MailMessage $message) => $message->line('Reason: '.$this->alert->reason))
            ->when(filled($this->alert->note), fn (MailMessage $message) => $message->line('Note: '.$this->alert->note));
    }
}
