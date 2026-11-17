<?php

namespace Pterodactyl\Tests\Integration\Notifications;

use Pterodactyl\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Pterodactyl\Notifications\AccountCreated;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

class AccountCreatedTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // A real queue and worker. The sync driver would run the job before the user could
        // be deleted; here the job is stored, the user is deleted, and the worker then reads
        // the job back and has to restore a model that is gone.
        config()->set('queue.default', 'database');
    }

    /**
     * An account that is deleted before the worker reaches its notification has nobody to
     * notify. The job is discarded and nothing is recorded as failed.
     */
    public function testNotificationForADeletedUserIsDiscarded(): void
    {
        $user = User::factory()->create();
        $user->notify(new AccountCreated($user));
        $this->assertSame(1, DB::table('jobs')->count());

        $user->delete();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * Control for the test above. The same sequence with a notification that does not opt in
     * is recorded as a failed job, so the empty table above means the job was discarded and
     * not that failures go unrecorded here.
     */
    public function testNotificationThatDoesNotOptInIsRecordedAsFailed(): void
    {
        $user = User::factory()->create();
        $user->notify(new MissingModelControlNotification($user));
        $this->assertSame(1, DB::table('jobs')->count());

        $user->delete();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringContainsString('ModelNotFoundException', DB::table('failed_jobs')->value('exception'));
    }
}
