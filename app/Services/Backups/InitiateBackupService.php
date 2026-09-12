<?php

namespace Pterodactyl\Services\Backups;

use Ramsey\Uuid\Uuid;
use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Extensions\Backups\BackupManager;
use Pterodactyl\Repositories\Eloquent\BackupRepository;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class InitiateBackupService
{
    private array $ignoredFiles = [];

    private bool $isLocked = false;

    /**
     * InitiateBackupService constructor.
     */
    public function __construct(
        private BackupRepository $repository,
        private ConnectionInterface $connection,
        private DaemonBackupRepository $daemonBackupRepository,
        private DeleteBackupService $deleteBackupService,
        private BackupManager $backupManager,
    ) {
    }

    /**
     * Set if the backup should be locked once it is created which will prevent
     * its deletion by users or automated system processes.
     */
    public function setIsLocked(bool $isLocked): self
    {
        $this->isLocked = $isLocked;

        return $this;
    }

    /**
     * Sets the files to be ignored by this backup.
     *
     * @param string[]|null $ignored
     */
    public function setIgnoredFiles(?array $ignored): self
    {
        if (is_array($ignored)) {
            foreach ($ignored as $value) {
                Assert::string($value); // @phpstan-ignore staticMethod.alreadyNarrowedType
            }
        }

        // Set the ignored files to be any values that are not empty in the array. Don't use
        // the PHP empty function here incase anything that is "empty" by default (0, false, etc.)
        // were passed as a file or folder name.
        $this->ignoredFiles = is_null($ignored) ? [] : array_filter($ignored, function ($value) {
            return strlen($value) > 0;
        });

        return $this;
    }

    /**
     * Initiates the backup process for a server on Wings.
     *
     * @throws \Throwable
     * @throws TooManyBackupsException
     * @throws TooManyRequestsHttpException
     */
    public function handle(Server $server, ?string $name = null, bool $override = false, ?string $replaceUuid = null): Backup
    {
        if ($replaceUuid !== null && $this->connection->transactionLevel() > 0) {
            throw new \LogicException('Replacement creation requires its own committed reservation.');
        }

        // Lock the server itself: locking existing backups cannot serialize the
        // first backup, and every caller (including scheduled panel tasks) must
        // share the same quota lock.
        $backup = $this->connection->transaction(function () use ($server, $name, $override, $replaceUuid) {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            return $this->create($server, $name, $override, $replaceUuid);
        });

        if ($replaceUuid !== null) {
            // This record and its audit event are committed before contacting
            // Wings. A timeout cannot erase the identity of an uncertain upload.
            $this->daemonBackupRepository->setServer($server)
                ->setBackupAdapter($backup->disk)->backup($backup);
        }

        return $backup;
    }

    private function create(Server $server, ?string $name, bool $override, ?string $replaceUuid): Backup
    {
        $limit = config('backups.throttles.limit');
        $period = config('backups.throttles.period');
        if ($period > 0) {
            $previous = $this->repository->getBackupsGeneratedDuringTimespan($server->id, $period);
            if ($previous->count() >= $limit) {
                $message = sprintf('Only %d backups may be generated within a %d second span of time.', $limit, $period);

                throw new TooManyRequestsHttpException((int) CarbonImmutable::now()->diffInSeconds($previous->last()->created_at->addSeconds($period)), $message);
            }
        }

        $successful = $this->repository->getNonFailedBackups($server);
        $count = $successful->count();
        // One unresolved replacement at a time, including failed replacements.
        // Delete the failed candidate to abandon it; never discard its source.
        if ($server->backups()->whereHas('replacementTarget')->exists()) {
            throw new ConflictHttpException('Reconcile the existing backup replacement before creating another backup.');
        }

        if ($replaceUuid !== null) {
            $target = $server->backups()->where('uuid', $replaceUuid)->first();
            if ($server->backup_limit <= 0 || $count !== $server->backup_limit
                || $server->backups()->whereNull('completed_at')->exists()
                || !$target || $target->is_locked || !$target->is_successful
                || !$target->completed_at || !$target->bytes || !$target->checksum || $this->isLocked) {
                throw new ConflictHttpException('A replacement requires a full quota and an unlocked, completed recovery point.');
            }
        } elseif (!$server->backup_limit || $count >= $server->backup_limit) {
            if (!$override || $server->backup_limit <= 0) {
                throw new TooManyBackupsException($server->backup_limit);
            }

            // Preserve the legacy panel-schedule override. The explicit Client
            // API replacement path above never uses delete-before-create.
            $oldest = $successful->where('is_locked', false)->orderBy('created_at')->first();
            if (!$oldest) {
                throw new TooManyBackupsException($server->backup_limit);
            }

            $this->deleteBackupService->handle($oldest);
        }

        return $this->connection->transaction(function () use ($server, $name, $replaceUuid) {
            /** @var Backup $backup */
            $backup = $this->repository->create([
                'server_id' => $server->id,
                'uuid' => Uuid::uuid4()->toString(),
                'name' => trim($name) ?: sprintf('Backup at %s', CarbonImmutable::now()->toDateTimeString()),
                'ignored_files' => array_values($this->ignoredFiles),
                'disk' => $this->backupManager->getDefaultAdapter(),
                'is_locked' => $this->isLocked,
                'replaces_backup_uuid' => $replaceUuid,
            ], true, true);

            if ($replaceUuid === null) {
                $this->daemonBackupRepository->setServer($server)
                    ->setBackupAdapter($this->backupManager->getDefaultAdapter())
                    ->backup($backup);
            } else {
                Activity::event('server:backup.start')->subject($backup)->property([
                    'name' => $backup->name, 'locked' => false, 'replaces' => $replaceUuid,
                ])->log();
            }

            return $backup;
        });
    }
}
