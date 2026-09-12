# Explicit backup replacement

Tracking: `platform-jm1c.1` in `hostvaultio/platform`.

The Client API can reserve one temporary backup above a server's configured limit
without first deleting a recovery point. This is an explicit protocol for the
Hostvault scheduled worker; ordinary creation retains its quota enforcement.

```http
POST /api/client/servers/{server}/backups
Content-Type: application/json

{"name":"Hostvault scheduled <run UUID>","replace_uuid":"<existing backup UUID>"}
```

The caller needs both `backup.create` and `backup.delete`. The selected backup must
belong to this server, be unlocked, and have successful completion metadata,
nonzero bytes and a checksum. The quota must be positive and exactly full, no
upload may be pending, and the candidate cannot be created locked. Invalid
replacement state returns 409; permission failures return 403. Existing creation
throttles still apply.

Replacement identity and its audit event commit before the Wings request. A
network error can therefore return an error response while leaving a pending
candidate. Reconcile that UUID/name through the backup list; never repeat the POST
merely because its response was lost. Source protection and the occupied slot
remain until the candidate reaches a terminal state. A process killed before
dispatch also leaves a pending reservation; inspect Wings before classifying it
as failed. The existing stale-backup pruner can mark abandoned uploads failed.

The response and subsequent list/view responses expose `replaces_backup_uuid`.
The source UUID remains for audit after its soft deletion. One unresolved
replacement is allowed per server, including failed candidates. While both source
and candidate exist, creation is refused through all service callers. Server-row
locks serialize quota checks, creation, deletion and lock toggles, including the
first backup when there are no backup rows to lock.

A worker must persist creation intent, reconcile unknown responses, confirm the
exact candidate completed successfully off-node, and restore Minecraft saving
before deleting the source. The panel independently refuses source deletion until
the candidate has successful completion metadata, positive bytes and a checksum.
S3's existing completion callback commits that metadata only after multipart
completion succeeds. A source locked after reservation remains protected.

Worker deletions must include a preservation precondition:
`DELETE /api/client/servers/{server}/backups/{uuid}?preserve_uuid={other_uuid}`.
The other backup must be different, belong to this server, still exist and have
successful completion metadata, positive bytes and a checksum. This check shares
the server deletion lock, so a concurrent deletion cannot invalidate an earlier
list response. A missing/invalid recovery point returns 409; malformed UUIDs return
422. Source pruning preserves the candidate; candidate abandonment preserves the
source. Ordinary manual deletes without the condition retain existing semantics.

Deleting the successfully replaced source through this conditional DELETE endpoint
releases the extra slot. Alternatively, a completed failed candidate can be deleted
to abandon replacement and preserve the source. An uploading candidate cannot be
deleted: reconcile its terminal state first. No callback automatically deletes
anything. Both copies may remain above quota after a worker failure; this bounded
state is intentional until recovery chooses one. Do not increase `backup_limit`
to work around an unresolved replacement.

The panel's built-in scheduled-task `override` path retains its prior behavior.
Hostvault's worker must use `replace_uuid`, not that delete-before-create override.

## Rollout and rollback

1. Apply the additive migration with panel backup activity quiesced. Drain old PHP
   requests and queue jobs before enabling replacement callers; old code does not
   enforce the new source protection. Deploy code, refresh application caches and
   restart PHP/queue processes using the panel's normal deployment procedure.
2. Deploy the Hostvault worker integration separately. Its rollout also requires
   paused schedules until old application tasks drain. This panel change alone
   does not activate recurring backups.
3. On a disposable server, run full-quota replacements at limits 1 and 3. Verify
   source preservation on upload failure and lost responses, successful OVH object
   completion, confirmed autosave recovery, source deletion and an actual restore.
   Repeat with locked backups and task replacement before enabling the real schedule.

Rollback refuses to remove the reference column while a nondeleted source and
candidate remain. Reconcile those pairs and stop replacement callers first.
The UUID is stored without a foreign key because panel backups are soft-deleted.

## Verification

`BackupReplacementTest` uses the real MySQL schema and HTTP authorization pipeline
with mocked Wings transport. It covers limit-one rotation, unauthorized and
cross-server requests, invalid/incomplete recovery points, ordinary quota
behavior, concurrent independent processes, failed-candidate abandonment, lock
changes, pending-upload deletion and migration rollback protection. Run it only
against an isolated test database, as guarded by `bootstrap/tests.php`:

```sh
php vendor/bin/phpunit tests/Integration/Api/Client/Server/Backup
```

These checks prove the API and database rules. They do not replace the live
full-quota worker/OVH restore gate above.

On 2026-09-12, the [recorded verification](backup-replacement-verification-2026-09-12.json)
passed 33 HTTP/API tests (127 assertions) and 6 existing backup-service tests
(21 assertions) against isolated MySQL 8.4.11 with PHP 8.3.33. Both real two-process
quota races passed. A separate database connection observed committed replacement
identity before a forced Wings timeout; retry was refused and the source survived.
The fork's PHP style check passed for all eight changed PHP files. Test containers,
network, source, dependencies and credentials were removed; the retained game
container kept its start time. No panel code was deployed by these checks.
