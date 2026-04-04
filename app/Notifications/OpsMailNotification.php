<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OpsMailNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        private readonly string $subject,
        private readonly array $lines,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message;
    }
}
