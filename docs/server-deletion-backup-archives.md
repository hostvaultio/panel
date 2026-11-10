# Server deletion removes backup archives

Tracking: `platform-kq3h` in `hostvaultio/platform`.

Deleting a server now deletes the backup archives it keeps in S3-compatible storage.
Upstream deletes the server on Wings, its databases and its row, and the row's
deletion cascades to the server's backup records. Nothing removed the objects those
records pointed at, so every archive of a deleted server stayed in the bucket with
nothing left that knew it existed. On 2026-11-10 a production safe delete through the
Application API left both archives of the deleted server in the bucket, about 240 MB
each.

## Rules

`ServerDeletionService` is the one path every server deletion takes: the admin area,
the Application API and failed-creation rollback. It now works in this order:

1. Delete the server on Wings (unchanged).
2. Delete the server's databases and clear its allocation notes, in one transaction
   (unchanged, except that the server row is no longer deleted inside it).
3. Remove the archive of each backup record whose disk is `s3`.
4. Delete the server row, which cascades to whatever records remain.

The archives go last so that a failure in an earlier step leaves the server's
recovery points alone. For each archive:

- The object `<server uuid>/<backup uuid>.tar.gz` is deleted from the configured
  bucket together with its record, in one transaction, as when a single backup is
  deleted. If the object cannot be removed the record stays; once it is removed
  nothing lists it. Deleting an object that does not exist succeeds, so a failed
  backup with no object is not an error.
- A backup that never succeeded and has an `upload_id` first has its multipart upload
  aborted, so the uploaded parts are not left behind. That covers an upload still
  running and one the stale-backup pruner marked as failed. An abort never stops the
  deletion: leftover parts are not a reason to keep a server, and a failed backup
  whose upload was already aborted or has expired answers 404, which is expected and
  not logged. Any other abort failure is logged with the object key.
- Locked backups and backups that are part of an unfinished replacement are deleted
  like any other. Those guards protect a recovery point of a server that continues
  to exist.
- Soft-deleted backup records are skipped. Their object was removed when the
  backup was deleted.
- Backups on the `wings` disk are not handled. Upstream Wings keeps the archives in
  its backup directory when it deletes a server (checked against its `develop`
  branch on 2026-11-10: the delete handler removes only the server's data
  directory), and they can only be deleted through Wings while the server still
  exists there. Hostvault stores every backup in S3 in both environments; an
  environment that moves to the `wings` disk has to solve that first.

## Failure and retry

A regular delete stops at the first object that cannot be deleted. At that point the
server is gone from Wings, its databases are deleted, and the server row is still
there. The backup records match the bucket: an archive that was removed has no live
record, and one that was not is intact. Repeating the delete resumes: Wings answers
404, which is accepted, no database is left, and the remaining objects are deleted.
Callers that retry a failed deletion, such as the Hostvault billing teardown, need no
change.

A force delete continues past every failure, as it always has: past a Wings error,
past a database that cannot be dropped, and now past an object that cannot be
deleted or an S3 disk that cannot be built. It logs a warning naming the object key.
That object stays in the bucket and must be removed by hand, as a database left on
its host must be after a forced delete.

Not covered: parts of an upload whose backup record was soft-deleted earlier. The
single-backup delete has never aborted uploads, so those parts have no record left
by the time the server is deleted. A bucket rule that aborts incomplete multipart
uploads is the remedy for those.

## Verification

`ServerDeletionServiceTest` uses the real MySQL schema with mocked Wings and
object-storage clients. It covers deletion of plain and locked archives with no
request for local or already-deleted backups and no abort for a completed one;
aborting before deleting for a running and a pruned upload; an abort that fails with
404 and one that fails otherwise; a database failure leaving archives untouched; a
storage failure stopping a regular delete with records that match the bucket; and a
force delete continuing to the next archive.

```sh
php vendor/bin/phpunit tests/Integration/Services/Servers/ServerDeletionServiceTest.php
```

These checks prove the service's calls and database rules. They do not prove an
actual deletion against AWS S3 or OVH Object Storage. Acceptance in each environment
is a disposable server with a completed backup: delete the server, then confirm the
object is absent from the bucket.

## Deployment

No migration, configuration or dependency change. The Panel's storage credentials
already allow object deletion and upload aborts, which backup deletion and failed
uploads use today. Archives orphaned before this release are not found by it: compare
the bucket's UUID-shaped top-level prefixes with the Panel's server UUIDs to find
them. Other top-level prefixes, such as a database dump prefix sharing the bucket,
are not orphans.
