<?php

namespace Pterodactyl\Tests\Integration\Notifications;

use Pterodactyl\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A queued notification shaped like AccountCreated that does not opt in to being discarded
 * when its user is gone. AccountCreatedTest uses it as the control case.
 */
class MissingModelControlNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user)
    {
    }

    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        return new MailMessage();
    }
}
