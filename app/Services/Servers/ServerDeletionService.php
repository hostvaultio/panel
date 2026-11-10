<?php

namespace Pterodactyl\Services\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Extensions\Filesystem\S3Filesystem;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Databases\DatabaseManagementService;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class ServerDeletionService
{
    protected bool $force = false;

    /**
     * ServerDeletionService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
        private DatabaseManagementService $databaseManagementService,
        private BackupManager $backupManager,
    ) {
    }

    /**
     * Set if the server should be forcibly deleted from the panel (ignoring daemon errors) or not.
     */
    public function withForce(bool $bool = true): self
    {
        $this->force = $bool;

        return $this;
    }

    /**
     * Delete a server from the panel, clear any allocation notes, and remove any associated databases from hosts
     * and backup archives from object storage.
     *
     * @throws \Throwable
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function handle(Server $server): void
    {
        try {
            $this->daemonServerRepository->setServer($server)->delete();
        } catch (DaemonConnectionException $exception) {
            // If there is an error not caused a 404 error and this isn't a forced delete,
            // go ahead and bail out. We specifically ignore a 404 since that can be assumed
            // to be a safe error, meaning the server doesn't exist at all on Wings so there
            // is no reason we need to bail out from that.
            if (!$this->force && $exception->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                throw $exception;
            }

            Log::warning($exception);
        }

        $this->connection->transaction(function () use ($server) {
            foreach ($server->databases as $database) {
                try {
                    $this->databaseManagementService->delete($database);
                } catch (\Exception $exception) {
                    if (!$this->force) {
                        throw $exception;
                    }

                    // Oh well, just try to delete the database entry we have from the database
                    // so that the server itself can be deleted. This will leave it dangling on
                    // the host instance, but we couldn't delete it anyways so not sure how we would
                    // handle this better anyways.
                    //
                    // @see https://github.com/pterodactyl/panel/issues/2085
                    $database->delete();

                    Log::warning($exception);
                }
            }

            // clear any allocation notes for the server
            $server->allocations()->update(['notes' => null]);
        });

        // The archives go last, so a failure above leaves the server's recovery points alone.
        // They are removed outside the transaction above because each archive is committed
        // with its own record: a failure part-way leaves no record pointing at a missing
        // object, and a repeated delete resumes with what is left.
        $this->deleteBackupArchives($server);

        $server->delete();
    }

    /**
     * Remove the server's backup archives from S3-compatible storage. Deleting the server
     * cascades to its backup records, after which nothing knows which objects belonged to
     * it and they would be kept in the bucket indefinitely. Archives on the "wings" disk are
     * not handled: they would have to be deleted through Wings before the server is, and
     * Hostvault keeps every backup in S3.
     *
     * Every recorded archive goes with its server, including locked ones and ones that are
     * part of an unfinished replacement: those guards protect a recovery point of a server
     * that continues to exist. Records that were already deleted lost their object at that
     * time and are not requested again.
     *
     * @throws \Throwable
     */
    private function deleteBackupArchives(Server $server): void
    {
        $backups = $server->backups()->where('disk', Backup::ADAPTER_AWS_S3)->get();

        foreach ($backups as $backup) {
            $key = sprintf('%s/%s.tar.gz', $server->uuid, $backup->uuid);

            try {
                $this->deleteArchive($backup, $key);
            } catch (\Throwable $exception) {
                if (!$this->force) {
                    throw $exception;
                }

                // The object stays in the bucket. Name it, since the record that pointed at it
                // is about to be deleted with the server.
                Log::warning('Could not delete a backup archive while force deleting its server.', [
                    'key' => $key,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete one archive and its record together, as when a single backup is deleted: if the
     * object cannot be removed the record stays, and once it is removed nothing lists it.
     *
     * @throws \Throwable
     */
    private function deleteArchive(Backup $backup, string $key): void
    {
        /** @var S3Filesystem $adapter */
        $adapter = $this->backupManager->adapter(Backup::ADAPTER_AWS_S3);
        $object = ['Bucket' => $adapter->getBucket(), 'Key' => $key];

        // A backup that never succeeded can have uploaded parts and no object. That covers an
        // upload still running and one the stale-backup pruner marked as failed.
        if (!$backup->is_successful && !empty($backup->upload_id)) {
            $this->abortUpload($adapter, $object + ['UploadId' => $backup->upload_id]);
        }

        $this->connection->transaction(function () use ($backup, $adapter, $object) {
            $backup->delete();

            // @phpstan-ignore-next-line method.notFound
            $adapter->getClient()->deleteObject($object);
        });
    }

    /**
     * Abort a multipart upload so its parts are not left in the bucket. This never stops the
     * deletion: leftover parts are not a reason to keep a server, and a failed backup whose
     * upload was already aborted or has expired answers 404, which is the expected case.
     */
    private function abortUpload(S3Filesystem $adapter, array $upload): void
    {
        try {
            // @phpstan-ignore-next-line method.notFound
            $adapter->getClient()->abortMultipartUpload($upload);
        } catch (\Throwable $exception) {
            if ($exception instanceof S3Exception && $exception->getStatusCode() === Response::HTTP_NOT_FOUND) {
                return;
            }

            Log::warning('Could not abort an unfinished backup upload while deleting its server.', [
                'key' => $upload['Key'],
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
