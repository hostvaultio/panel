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
        $worker = $this->runWorkerOnce();

        $this->assertSame(0, DB::table('jobs')->count(), $worker);
        $this->assertSame(0, DB::table('failed_jobs')->count(), $worker);
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
        $worker = $this->runWorkerOnce();

        $this->assertSame(0, DB::table('jobs')->count(), $worker);
        $this->assertSame(1, DB::table('failed_jobs')->count(), $worker);
        $this->assertStringContainsString('ModelNotFoundException', DB::table('failed_jobs')->value('exception'));
    }

    /**
     * Let the queue worker take one job from the database queue. Returns what the worker
     * printed and what is left in the queue, which an assertion shows when it fails.
     */
    private function runWorkerOnce(): string
    {
        $exit = Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

        return sprintf(
            "queue:work exited %d and printed:\n%s\njobs left: %s\nnow: %d",
            $exit,
            Artisan::output(),
            DB::table('jobs')->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at'])->toJson(),
            now()->getTimestamp(),
        );
    }
}
