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
the Application API and failed-creation rollback. After the Wings deletion succeeds
and before the database transaction, it handles each of the server's backup records
whose disk is `s3`:

- It deletes the object `<server uuid>/<backup uuid>.tar.gz` from the configured
  bucket. Deleting an object that does not exist succeeds, so a failed backup with
  no object is not an error.
- For a backup that never completed and has an `upload_id`, it first aborts the
  multipart upload, so the uploaded parts are not left behind. A failed abort is
  logged and does not stop the deletion: a stale record whose upload already
  expired must not block it.
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

The archives go only after the server is gone from Wings. If the Wings deletion
fails, the server keeps its recovery points.

## Failure and retry

A regular delete stops when an object cannot be deleted. The server row, its backup
records and its databases are untouched at that point, and the server is already
gone from Wings. Repeating the delete resumes: Wings answers 404, which is accepted,
and the remaining objects are deleted. Callers that retry a failed deletion, such as
the Hostvault billing teardown, need no change.

A force delete logs a warning naming the object key and continues. The object then
stays in the bucket and must be removed by hand, as a database left on its host
must be after a forced delete.

## Verification

`ServerDeletionServiceTest` uses the real MySQL schema with mocked Wings and
object-storage clients. It covers deletion of plain and locked archives, no request
for local or already-deleted backups, aborting an unfinished upload, a failed abort,
a storage failure stopping a regular delete with the records intact, and a force
delete continuing past it.

```sh
php vendor/bin/phpunit tests/Integration/Services/Servers/ServerDeletionServiceTest.php
```

These checks prove the service's calls and database rules. They do not prove an
actual deletion against AWS S3 or OVH Object Storage. Acceptance in each environment
is a disposable server with a completed backup: delete the server, then confirm the
object is absent from the bucket.

## Deployment

No migration, configuration or dependency change. The Panel's storage credentials
already allow object deletion, which backup deletion uses today. Archives orphaned
before this release are not found by it; list the bucket's top-level prefixes against
the Panel's server UUIDs to find them.
