<?php

namespace Pterodactyl\Notifications;

use Pterodactyl\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class AccountCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * The account is the notifiable and is read from there when the mail is built. A user
     * model stored on the notification has to be restored when the queued job is read back,
     * and that fails the job if the account was deleted in the meantime. Without one, the
     * job finds nobody left to notify and completes.
     */
    public function __construct(public ?string $token = null)
    {
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('You are receiving this email because an account has been created for you on ' . config('app.name') . '.')
            ->line('Username: ' . $notifiable->username)
            ->line('Email: ' . $notifiable->email);

        if (!is_null($this->token)) {
            return $message->action('Setup Your Account', url('/auth/password/reset/' . $this->token . '?email=' . urlencode($notifiable->email)));
        }

        return $message;
    }
}
