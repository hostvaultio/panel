<?php

namespace Pterodactyl\Tests\Integration\Notifications;

use Pterodactyl\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Pterodactyl\Notifications\AccountCreated;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

class AccountCreatedTest extends IntegrationTestCase
{
    /**
     * An account that is deleted before the worker reaches its notification has nobody to
     * notify. The queued job completes, and nothing is recorded as failed.
     *
     * This runs a real queue and worker. The sync driver would run the job before the user
     * could be deleted; here the job is stored, the user is deleted, and the worker then
     * reads the job back. While the notification stored the user model, that read failed
     * the job with a ModelNotFoundException.
     */
    public function testQueuedNotificationForADeletedUserCompletes(): void
    {
        config()->set('queue.default', 'database');
        $failedBefore = DB::table('failed_jobs')->count();

        $user = User::factory()->create();
        $user->notify(new AccountCreated('reset-token'));
        $this->assertSame(1, DB::table('jobs')->count());

        $user->delete();
        $exit = Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('DONE', $output);
        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertSame(0, DB::table('jobs')->count(), $output);
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count(), $output);
    }

    public function testMailIsBuiltFromTheNotifiedAccount(): void
    {
        $user = User::factory()->create();

        $mail = (new AccountCreated('reset-token'))->toMail($user);

        $this->assertSame('Hello ' . $user->name . '!', $mail->greeting);
        $this->assertContains('Username: ' . $user->username, $mail->introLines);
        $this->assertContains('Email: ' . $user->email, $mail->introLines);
        $this->assertSame('Setup Your Account', $mail->actionText);
        $this->assertSame(
            url('/auth/password/reset/reset-token?email=' . urlencode($user->email)),
            $mail->actionUrl
        );
    }

    public function testMailHasNoSetupLinkWithoutAToken(): void
    {
        $user = User::factory()->create();

        $mail = (new AccountCreated())->toMail($user);

        $this->assertNull($mail->actionUrl);
        $this->assertContains('Username: ' . $user->username, $mail->introLines);
    }
}
