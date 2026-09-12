<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Backup;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class BackupReplacementTest extends ClientApiIntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        config()->set('backups.throttles.period', 0);
        $daemon = $this->mock(DaemonBackupRepository::class);
        $daemon->allows('setServer')->andReturnSelf();
        $daemon->allows('setBackupAdapter')->andReturnSelf();
        $daemon->allows('backup')->andReturn(new Response());
        $daemon->allows('delete')->andReturn(new Response());
    }

    private function source(Server $server, array $attributes = []): Backup
    {
        return Backup::factory()->create(array_merge([
            'server_id' => $server->id, 'bytes' => 100, 'checksum' => 'sha1:proof', 'ignored_files' => [],
        ], $attributes));
    }

    public function testLimitOnePreservesOriginalUntilReplacementCompletes(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 1]);
        $source = $this->source($server);
        $response = $this->actingAs($user)->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])
            ->assertOk()->assertJsonPath('attributes.replaces_backup_uuid', $source->uuid);
        $uuid = $response->json('attributes.uuid');
        $this->assertSame(2, $server->backups()->count());
        $this->assertNull($source->fresh()->deleted_at);
        $this->deleteJson($this->link($source))->assertStatus(409);
        $this->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertStatus(409);
        $this->postJson($this->link($server, '/backups'))->assertStatus(409);

        Backup::query()->where('uuid', $uuid)->update([
            'is_successful' => true, 'completed_at' => CarbonImmutable::now(), 'bytes' => 200, 'checksum' => 'sha1:new',
        ]);
        $this->deleteJson($this->link($source))->assertNoContent();
        $this->assertSoftDeleted($source);
        $this->assertSame(1, $server->backups()->count());
        $this->postJson($this->link($server, '/backups'), ['replace_uuid' => $uuid])->assertOk();
        $this->assertSame(2, $server->backups()->count());
    }

    public function testReplacementAlsoRequiresDeletePermission(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_BACKUP_CREATE]);
        $server->update(['backup_limit' => 1]);
        $source = $this->source($server);
        $this->actingAs($user)->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertForbidden();
        $this->assertSame(1, $server->backups()->count());
    }

    public function testReplacementStillRequiresCreatePermission(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_BACKUP_DELETE]);
        $server->update(['backup_limit' => 1]);
        $source = $this->source($server);
        $this->actingAs($user)->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertForbidden();
    }

    public function testNormalCreationCannotUseTheReservedSlot(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 1]);
        $this->source($server);
        $this->actingAs($user)->postJson($this->link($server, '/backups'))->assertStatus(400);
        $this->assertSame(1, $server->backups()->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSourceProvider')]
    public function testInvalidRecoveryPointsCannotAuthorizeAnExtraSlot(array $attributes): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 1]);
        $source = $this->source($server, $attributes);
        $this->actingAs($user)->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertStatus(409);
        $this->assertSame(1, $server->backups()->count());
    }

    public static function invalidSourceProvider(): array
    {
        return [
            [['is_locked' => true]], [['is_successful' => false]], [['completed_at' => null]],
            [['bytes' => 0]], [['checksum' => null]],
        ];
    }

    public function testCannotReplaceAnotherServersBackup(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 1]);
        $this->source($server);
        $foreign = $this->source($this->createServerModel());
        $this->actingAs($user)->postJson($this->link($server, '/backups'), ['replace_uuid' => $foreign->uuid])->assertStatus(409);
        $this->assertSame(1, $server->backups()->count());
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQuotaProvider')]
    public function testReplacementRequiresExactlyTheConfiguredQuota(int $limit): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => $limit]);
        $source = $this->source($server);
        $this->actingAs($user)->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertStatus(409);
    }

    public static function invalidQuotaProvider(): array
    {
        return [[0], [2]];
    }

    public function testFailedReplacementCanBeAbandonedWithoutRemovingOriginal(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 1]);
        $source = $this->source($server);
        $candidate = $this->source($server, ['replaces_backup_uuid' => $source->uuid, 'is_successful' => false, 'bytes' => 0, 'checksum' => null]);
        $this->actingAs($user)->deleteJson($this->link($source))->assertStatus(409);
        $this->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertStatus(409);
        $this->deleteJson($this->link($candidate))->assertNoContent();
        $this->assertNull($source->fresh()->deleted_at);
        $this->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertOk();
    }

    public function testLockAppliedDuringReplacementStillProtectsTheSource(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 1]);
        $source = $this->source($server);
        $this->source($server, ['replaces_backup_uuid' => $source->uuid]);
        $this->actingAs($user)->postJson($this->link($source, '/lock'))->assertOk();
        $this->deleteJson($this->link($source))->assertStatus(400);
        $this->assertSame(2, $server->backups()->count());
    }

    public function testZeroByteCompletedReplacementDoesNotPermitSourceDeletion(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $source = $this->source($server);
        $this->source($server, ['replaces_backup_uuid' => $source->uuid, 'bytes' => 0]);
        $this->actingAs($user)->deleteJson($this->link($source))->assertStatus(409);
        $this->assertNull($source->fresh()->deleted_at);
    }

    public function testAllLockedQuotaAndLockedReplacementAreRejected(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 3]);
        $source = $this->source($server, ['is_locked' => true]);
        $this->source($server, ['is_locked' => true]);
        $this->source($server, ['is_locked' => true]);
        $this->actingAs($user)->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertStatus(409);
        $source->update(['is_locked' => false]);
        $this->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid, 'is_locked' => true])->assertStatus(409);
        $this->assertSame(3, $server->backups()->count());
    }

    public function testPendingReplacementCannotBeDeletedWhileUploading(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $source = $this->source($server);
        $candidate = $this->source($server, ['replaces_backup_uuid' => $source->uuid, 'completed_at' => null]);
        $this->actingAs($user)->deleteJson($this->link($candidate))->assertStatus(409);
        $this->assertSame(2, $server->backups()->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creationRaceProvider')]
    public function testTwoProcessesCannotExceedTheAllowedSlots(bool $replacement): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl for real MySQL contention.');
        }
        $server = $this->createServerModel(['backup_limit' => 1]);
        $source = $replacement ? $this->source($server) : null;
        $directory = sys_get_temp_dir() . '/backup-contention-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->app->make('db')->purge();
        $children = [];
        for ($index = 0; $index < 2; ++$index) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $this->app->make('db')->purge();
                    file_put_contents($directory . '/' . $index . '.ready', 'ready');
                    $deadline = microtime(true) + 10;
                    while (count(glob($directory . '/*.ready')) < 2) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Child startup timed out.');
                        }
                        usleep(10000);
                    }
                    $service = $this->app->make(\Pterodactyl\Services\Backups\InitiateBackupService::class);
                    $service->handle($server, 'Concurrent creation', false, $source?->uuid);
                    $result = 'created';
                } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException|\Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException) {
                    $result = 'conflict';
                } catch (\Throwable $error) {
                    $result = get_class($error);
                }
                file_put_contents($directory . '/' . $index . '.result', $result);
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $results = array_map('file_get_contents', glob($directory . '/*.result'));
        sort($results);
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        $this->app->make('db')->purge();
        $this->assertSame(['conflict', 'created'], $results);
        $this->assertSame($replacement ? 2 : 1, $server->backups()->count());
        if ($source) {
            $this->assertNull($source->fresh()->deleted_at);
        }
    }

    public static function creationRaceProvider(): array
    {
        return [[false], [true]];
    }

    public function testWingsTimeoutRetainsACommittedReplacementIdentity(): void
    {
        [$user, $server] = $this->generateTestAccount();
        $server->update(['backup_limit' => 1]);
        $source = $this->source($server);
        config()->set('database.connections.backup_observer', config('database.connections.mysql'));
        $daemon = $this->mock(DaemonBackupRepository::class);
        $daemon->allows('setServer')->andReturnSelf();
        $daemon->allows('setBackupAdapter')->andReturnSelf();
        $daemon->expects('backup')->andReturnUsing(function (Backup $backup) {
            // A different connection can only observe a committed reservation.
            $this->assertSame(1, $this->app->make('db')->connection('backup_observer')
                ->table('backups')->where('uuid', $backup->uuid)->count());
            throw new \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException(new \GuzzleHttp\Exception\ConnectException('Connection timed out.', new \GuzzleHttp\Psr7\Request('POST', 'https://wings.invalid')));
        });
        $this->actingAs($user)->postJson($this->link($server, '/backups'), [
            'name' => 'Unique scheduled run', 'replace_uuid' => $source->uuid,
        ])->assertStatus(504);
        $candidate = $server->backups()->where('name', 'Unique scheduled run')->firstOrFail();
        $this->assertSame($source->uuid, $candidate->replaces_backup_uuid);
        $this->assertNull($source->fresh()->deleted_at);
        $this->assertNull($candidate->completed_at);
        $this->postJson($this->link($server, '/backups'), ['replace_uuid' => $source->uuid])->assertStatus(409);
        $this->assertSame(2, $server->backups()->count());
        $this->app->make('db')->purge('backup_observer');
    }

    public function testMigrationRefusesToDiscardUnresolvedReplacementState(): void
    {
        $server = $this->createServerModel();
        $source = $this->source($server);
        $candidate = $this->source($server, ['replaces_backup_uuid' => $source->uuid]);
        $migration = require database_path('migrations/2026_09_12_220000_add_backup_replacement_reference.php');
        $migration->up(); // Reapplying an additive migration is harmless.
        try {
            $migration->down();
            $this->fail('Rollback discarded a replacement.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Reconcile backup replacements before rollback.', $error->getMessage());
        }
        $candidate->delete();
        $migration->down();
        $migration->up();
        $this->assertNull($source->fresh()->replaces_backup_uuid);
    }
}
