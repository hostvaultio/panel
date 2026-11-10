<?php

namespace Pterodactyl\Services\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Extensions\Backups\BackupManager;
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

        // The archives go only once the server itself is gone from Wings: if that step fails,
        // the server keeps its recovery points. A failure here leaves the server record in
        // place, and a repeated delete resumes from this point.
        $this->deleteBackupArchives($server);

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


            $server->delete();
        });
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
            } catch (\Exception $exception) {
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
     * Delete one archive from S3-compatible storage, aborting its upload first when the backup
     * never completed.
     *
     * @throws \Throwable
     */
    private function deleteArchive(Backup $backup, string $key): void
    {
        /** @var \Pterodactyl\Extensions\Filesystem\S3Filesystem $adapter */
        $adapter = $this->backupManager->adapter(Backup::ADAPTER_AWS_S3);
        $object = ['Bucket' => $adapter->getBucket(), 'Key' => $key];

        // An upload still in progress has parts in the bucket but no object yet. Abort it so the
        // parts are not left behind. A stale record whose upload already expired must not block
        // the deletion, so a failed abort is only logged.
        if (is_null($backup->completed_at) && !empty($backup->upload_id)) {
            try {
                // @phpstan-ignore-next-line method.notFound
                $adapter->getClient()->abortMultipartUpload($object + ['UploadId' => $backup->upload_id]);
            } catch (\Exception $exception) {
                Log::warning($exception);
            }
        }

        // @phpstan-ignore-next-line method.notFound
        $adapter->getClient()->deleteObject($object);
    }
}
