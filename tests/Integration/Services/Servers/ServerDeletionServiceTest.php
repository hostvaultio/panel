<?php

namespace Pterodactyl\Tests\Integration\Services\Servers;

use Aws\Command;
use Mockery\MockInterface;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Database;
use Aws\S3\Exception\S3Exception;
use Pterodactyl\Models\DatabaseHost;
use GuzzleHttp\Exception\BadResponseException;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Extensions\Filesystem\S3Filesystem;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Databases\DatabaseManagementService;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class ServerDeletionServiceTest extends IntegrationTestCase
{
    private MockInterface $daemonServerRepository;

    private MockInterface $databaseManagementService;

    private static ?string $defaultLogger;

    /**
     * Stub out services that we don't want to test in here.
     */
    public function setUp(): void
    {
        parent::setUp();

        self::$defaultLogger = config('logging.default');
        // There will be some log calls during this test, don't actually write to the disk.
        config()->set('logging.default', 'null');

        $this->daemonServerRepository = \Mockery::mock(DaemonServerRepository::class);
        $this->databaseManagementService = \Mockery::mock(DatabaseManagementService::class);

        $this->app->instance(DaemonServerRepository::class, $this->daemonServerRepository);
        $this->app->instance(DatabaseManagementService::class, $this->databaseManagementService);
    }

    /**
     * Reset the log driver.
     */
    protected function tearDown(): void
    {
        config()->set('logging.default', self::$defaultLogger);
        self::$defaultLogger = null;

        parent::tearDown();
    }

    /**
     * Test that a server is not deleted if the force option is not set and an error
     * is returned by wings.
     */
    public function testRegularDeleteFailsIfWingsReturnsError()
    {
        $server = $this->createServerModel();

        $this->expectException(DaemonConnectionException::class);

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andThrows(
            new DaemonConnectionException(new BadResponseException('Bad request', new Request('GET', '/test'), new Response()))
        );

        $this->getService()->handle($server);

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    /**
     * Test that a 404 from Wings while deleting a server does not cause the deletion to fail.
     */
    public function testRegularDeleteIgnores404FromWings()
    {
        $server = $this->createServerModel();

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andThrows(
            new DaemonConnectionException(new BadResponseException('Bad request', new Request('GET', '/test'), new Response(404)))
        );

        $this->getService()->handle($server);

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    /**
     * Test that an error from Wings does not cause the deletion to fail if the server is being
     * force deleted.
     */
    public function testForceDeleteIgnoresExceptionFromWings()
    {
        $server = $this->createServerModel();

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andThrows(
            new DaemonConnectionException(new BadResponseException('Bad request', new Request('GET', '/test'), new Response(500)))
        );

        $this->getService()->withForce()->handle($server);

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    /**
     * Test that a non-force-delete call does not delete the server if one of the databases
     * cannot be deleted from the host.
     */
    public function testExceptionWhileDeletingStopsProcess()
    {
        $server = $this->createServerModel();
        $host = DatabaseHost::factory()->create();

        /** @var Database $db */
        $db = Database::factory()->create(['database_host_id' => $host->id, 'server_id' => $server->id]);

        $server->refresh();

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();
        $this->databaseManagementService->expects('delete')->with(\Mockery::on(function ($value) use ($db) {
            return $value instanceof Database && $value->id === $db->id;
        }))->andThrows(new \Exception());

        $this->expectException(\Exception::class);
        $this->getService()->handle($server);

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertDatabaseHas('databases', ['id' => $db->id]);
    }

    /**
     * Test that a server is deleted even if the server databases cannot be deleted from the host.
     */
    public function testExceptionWhileDeletingDatabasesDoesNotAbortIfForceDeleted()
    {
        $server = $this->createServerModel();
        $host = DatabaseHost::factory()->create();

        /** @var Database $db */
        $db = Database::factory()->create(['database_host_id' => $host->id, 'server_id' => $server->id]);

        $server->refresh();

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();
        $this->databaseManagementService->expects('delete')->with(\Mockery::on(function ($value) use ($db) {
            return $value instanceof Database && $value->id === $db->id;
        }))->andThrows(new \Exception());

        $this->getService()->withForce(true)->handle($server);

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('databases', ['id' => $db->id]);
    }

    /**
     * Test that the archives a server keeps in object storage are deleted with it, including a
     * locked one, and that nothing is requested for an archive Wings holds or for a backup that
     * was already deleted. A completed backup keeps its upload id, which must not be aborted.
     */
    public function testBackupArchivesInObjectStorageAreDeletedWithTheServer()
    {
        $server = $this->createServerModel();
        $plain = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_AWS_S3,
            'upload_id' => 'completed-upload',
        ]);
        $locked = Backup::factory()->create([
            'server_id' => $server->id,
            'disk' => Backup::ADAPTER_AWS_S3,
            'is_locked' => true,
        ]);
        Backup::factory()->create(['server_id' => $server->id]);
        Backup::factory()->create(['server_id' => $server->id, 'disk' => Backup::ADAPTER_AWS_S3])->delete();

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();

        $keys = [];
        $aborts = 0;
        $adapter = $this->mockObjectStorage();
        $adapter->shouldReceive('getClient->abortMultipartUpload')->andReturnUsing(function () use (&$aborts) {
            ++$aborts;
        });
        $adapter->shouldReceive('getClient->deleteObject')->twice()->with(\Mockery::on(function ($object) use (&$keys) {
            $keys[] = $object['Key'];

            return $object['Bucket'] === 'foobar' && count($object) === 2;
        }));

        $this->getService()->handle($server);

        $expected = [
            sprintf('%s/%s.tar.gz', $server->uuid, $plain->uuid),
            sprintf('%s/%s.tar.gz', $server->uuid, $locked->uuid),
        ];
        sort($expected);
        sort($keys);
        $this->assertSame($expected, $keys);
        $this->assertSame(0, $aborts);
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('backups', ['server_id' => $server->id]);
    }

    /**
     * Test that a backup that never succeeded has its multipart upload aborted before its
     * object is deleted, so its parts are not left in the bucket. That holds for an upload
     * still running and for one the stale-backup pruner already marked as failed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unfinishedUploadDataProvider')]
    public function testUnfinishedUploadIsAbortedWithTheServer(bool $markedFailed)
    {
        $server = $this->createServerModel();
        $backup = $this->createUnfinishedUpload($server->id, $markedFailed ? CarbonImmutable::now() : null);
        $object = ['Bucket' => 'foobar', 'Key' => sprintf('%s/%s.tar.gz', $server->uuid, $backup->uuid)];

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();

        $adapter = $this->mockObjectStorage();
        $adapter->expects('getClient->abortMultipartUpload')->with($object + ['UploadId' => 'upload-123'])->ordered();
        $adapter->expects('getClient->deleteObject')->with($object)->ordered();

        $this->getService()->handle($server);

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    public static function unfinishedUploadDataProvider(): array
    {
        return [
            'upload still running' => [false],
            'marked as failed by the pruner' => [true],
        ];
    }

    /**
     * Test that an upload that can no longer be aborted does not block the deletion, whether
     * object storage answers that the upload is gone or fails in some other way.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('abortFailureDataProvider')]
    public function testFailedUploadAbortDoesNotStopTheDeletion(bool $uploadGone)
    {
        $failure = new \Exception('storage unavailable');
        if ($uploadGone) {
            $failure = new S3Exception('The specified upload does not exist.', new Command('AbortMultipartUpload'), [
                'code' => 'NoSuchUpload',
                'response' => new Response(404),
            ]);
        }

        $server = $this->createServerModel();
        $backup = $this->createUnfinishedUpload($server->id);

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();

        $adapter = $this->mockObjectStorage();
        $adapter->expects('getClient->abortMultipartUpload')->andThrows($failure);
        $adapter->expects('getClient->deleteObject')->with([
            'Bucket' => 'foobar',
            'Key' => sprintf('%s/%s.tar.gz', $server->uuid, $backup->uuid),
        ]);

        $this->getService()->handle($server);

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    public static function abortFailureDataProvider(): array
    {
        return [
            'the upload is already gone' => [true],
            'object storage fails otherwise' => [false],
        ];
    }

    /**
     * Test that a regular delete that fails on a database leaves every archive and backup record
     * alone: the archives are the last thing to go.
     */
    public function testArchivesAreNotTouchedWhenADatabaseCannotBeDeleted()
    {
        $server = $this->createServerModel();
        $backup = Backup::factory()->create(['server_id' => $server->id, 'disk' => Backup::ADAPTER_AWS_S3]);
        $host = DatabaseHost::factory()->create();
        Database::factory()->create(['database_host_id' => $host->id, 'server_id' => $server->id]);
        $server->refresh();

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();
        $this->databaseManagementService->expects('delete')->andThrows(new \Exception('host unreachable'));
        $this->mock(BackupManager::class)->shouldNotReceive('adapter');

        $thrown = null;
        try {
            $this->getService()->handle($server);
        } catch (\Exception $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('host unreachable', $thrown->getMessage());
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertDatabaseHas('backups', ['id' => $backup->id, 'deleted_at' => null]);
    }

    /**
     * Test that a regular delete stops when an archive cannot be removed from object storage,
     * and that the records then match the bucket: the archive that was removed has no live
     * record left, and the one that was not is intact, so a repeated delete can finish the job.
     */
    public function testArchiveDeletionFailureStopsRegularDeleteAndKeepsRecordsTrue()
    {
        $server = $this->createServerModel();
        $backups = [
            Backup::factory()->create(['server_id' => $server->id, 'disk' => Backup::ADAPTER_AWS_S3]),
            Backup::factory()->create(['server_id' => $server->id, 'disk' => Backup::ADAPTER_AWS_S3]),
        ];

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();

        $keys = [];
        $adapter = $this->mockObjectStorage();
        $adapter->shouldReceive('getClient->deleteObject')->twice()->andReturnUsing(function ($object) use (&$keys) {
            $keys[] = $object['Key'];
            if (count($keys) === 2) {
                throw new \Exception('storage unavailable');
            }
        });

        $thrown = null;
        try {
            $this->getService()->handle($server);
        } catch (\Exception $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('storage unavailable', $thrown->getMessage());
        $this->assertDatabaseHas('servers', ['id' => $server->id]);
        $this->assertCount(2, $keys);
        foreach ($backups as $backup) {
            $key = sprintf('%s/%s.tar.gz', $server->uuid, $backup->uuid);
            if ($key === $keys[0]) {
                $this->assertSoftDeleted($backup);
            } else {
                $this->assertSame($keys[1], $key);
                $this->assertDatabaseHas('backups', ['id' => $backup->id, 'deleted_at' => null]);
            }
        }
    }

    /**
     * Test that a force delete removes the server even when an archive cannot be removed from
     * object storage, and still goes on to the server's other archives.
     */
    public function testArchiveDeletionFailureDoesNotAbortIfForceDeleted()
    {
        $server = $this->createServerModel();
        Backup::factory()->count(2)->create(['server_id' => $server->id, 'disk' => Backup::ADAPTER_AWS_S3]);

        $this->daemonServerRepository->expects('setServer->delete')->withNoArgs()->andReturnUndefined();

        $calls = 0;
        $adapter = $this->mockObjectStorage();
        $adapter->shouldReceive('getClient->deleteObject')->twice()->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \Exception('storage unavailable');
            }
        });

        $this->getService()->withForce()->handle($server);

        $this->assertSame(2, $calls);
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('backups', ['server_id' => $server->id]);
    }

    /**
     * Bind a mocked S3 adapter with the bucket "foobar" as the backup manager's S3 disk.
     */
    private function mockObjectStorage(): MockInterface
    {
        $adapter = $this->mock(S3Filesystem::class);
        $adapter->allows('getBucket')->andReturn('foobar');

        $this->mock(BackupManager::class)->allows('adapter')->with(Backup::ADAPTER_AWS_S3)->andReturn($adapter);

        return $adapter;
    }

    /**
     * Create an S3 backup whose multipart upload was started and never succeeded. Without a
     * completion time the upload is still running; with one, the backup was marked as failed.
     */
    private function createUnfinishedUpload(int $serverId, ?CarbonImmutable $completedAt = null): Backup
    {
        return Backup::factory()->create([
            'server_id' => $serverId,
            'disk' => Backup::ADAPTER_AWS_S3,
            'upload_id' => 'upload-123',
            'is_successful' => false,
            'completed_at' => $completedAt,
        ]);
    }

    private function getService(): ServerDeletionService
    {
        return $this->app->make(ServerDeletionService::class);
    }
}
