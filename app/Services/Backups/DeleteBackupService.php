<?php

namespace Pterodactyl\Services\Backups;

use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Exceptions\Service\Backup\BackupLockedException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class DeleteBackupService
{
    public function __construct(
        private ConnectionInterface $connection,
        private BackupManager $manager,
        private DaemonBackupRepository $daemonBackupRepository,
    ) {
    }

    /**
     * Deletes a backup from the system. If the backup is stored in S3 a request
     * will be made to delete that backup from the disk as well.
     *
     * @throws \Throwable
     */
    public function handle(Backup $backup, ?string $preserveUuid = null): void
    {
        $this->connection->transaction(function () use ($backup, $preserveUuid) {
            Server::query()->whereKey($backup->server_id)->lockForUpdate()->firstOrFail();
            $backup->refresh();
            if ($preserveUuid !== null) {
                $preserved = Backup::query()->where('server_id', $backup->server_id)
                    ->where('uuid', $preserveUuid)->lockForUpdate()->first();
                if (!$preserved || $preserved->id === $backup->id || !$preserved->is_successful
                    || !$preserved->completed_at || !$preserved->bytes || !$preserved->checksum) {
                    throw new ConflictHttpException('The recovery point to preserve must still exist and be complete.');
                }
            }
            if ($backup->replaces_backup_uuid && !$backup->completed_at) {
                throw new ConflictHttpException('Wait for the replacement upload to finish before deleting it.');
            }
            $replacement = Backup::query()->where('server_id', $backup->server_id)
                ->where('replaces_backup_uuid', $backup->uuid)->lockForUpdate()->first();
            if ($replacement && (!$replacement->is_successful || !$replacement->completed_at
                || !$replacement->bytes || !$replacement->checksum)) {
                throw new ConflictHttpException('The replacement must complete successfully before deleting this recovery point.');
            }

            $this->delete($backup);
        });
    }

    private function delete(Backup $backup): void
    {
        // If the backup is marked as failed it can still be deleted, even if locked
        // since the UI doesn't allow you to unlock a failed backup in the first place.
        //
        // I also don't really see any reason you'd have a locked, failed backup to keep
        // around. The logic that updates the backup to the failed state will also remove
        // the lock, so this condition should really never happen.
        if ($backup->is_locked && ($backup->is_successful && !is_null($backup->completed_at))) {
            throw new BackupLockedException();
        }

        if ($backup->disk === Backup::ADAPTER_AWS_S3) {
            $this->deleteFromS3($backup);

            return;
        }

        $this->connection->transaction(function () use ($backup) {
            try {
                $this->daemonBackupRepository->setServer($backup->server)->delete($backup);
            } catch (DaemonConnectionException $exception) {
                $previous = $exception->getPrevious();
                // Don't fail the request if the Daemon responds with a 404, just assume the backup
                // doesn't actually exist and remove its reference from the Panel as well.
                if (!$previous instanceof ClientException || $previous->getResponse()->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                    throw $exception;
                }
            }

            $backup->delete();
        });
    }

    /**
     * Deletes a backup from an S3 disk.
     *
     * @throws \Throwable
     */
    protected function deleteFromS3(Backup $backup): void
    {
        $this->connection->transaction(function () use ($backup) {
            $backup->delete();

            /** @var \Pterodactyl\Extensions\Filesystem\S3Filesystem $adapter */
            $adapter = $this->manager->adapter(Backup::ADAPTER_AWS_S3);

            // @phpstan-ignore-next-line method.notFound
            $adapter->getClient()->deleteObject([
                'Bucket' => $adapter->getBucket(),
                'Key' => sprintf('%s/%s.tar.gz', $backup->server->uuid, $backup->uuid),
            ]);
        });
    }
}
