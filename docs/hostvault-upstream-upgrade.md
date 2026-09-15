# Hostvault panel upgrade to v1.15.1

Tracking: `platform-hayu` in `hostvaultio/platform`.

This candidate combines upstream tag `v1.15.1` (`e98678c5a`), the existing
database-host Application API, and the verified backup replacement protocol from
panel PR 3. It supersedes PR 3's older-base candidate; do not deploy the two as
independent releases. Its target is the new `hostvault/v1.15.1` release branch,
created from the upstream tag. The existing `hostvault/v1.12.4` branch stays intact.

The database-host API remains necessary for registering co-located MariaDB through
automation. Upstream v1.15.1 still uses delete-before-create for the built-in backup
override, so it does not replace our source-preserving replacement contract. See
[backup-replacement.md](backup-replacement.md) for the API and recovery rules.

## Validation boundary

The fork now runs PR checks targeting `hostvault/**`: PHP 8.2 and 8.3 with isolated MySQL
8.4.9, PHP style, the upstream unit/integration suites, custom database-host HTTP
tests, and backup replacement tests including independent-process races. The
upstream UI checks also run on these branches with Node 22. CI has read-only
repository access and no production credentials. Its fixed database password is
only for the disposable job service.

Database-host tests cover real local database verification and encrypted credential
storage, response redaction, read/write API permissions, validation and deletion.
Wings transport is mocked in panel backup tests; those tests cannot establish
actual OVH uploads, Minecraft save recovery or a deployed worker's compatibility.
The September 12 backup verification artifact describes the earlier PHP/Laravel
base and must not be presented as validation of this upgrade.

Upstream's CDN dispatch is restricted to the upstream repository; a Hostvault
release must never send an upstream release announcement.

## Deployment gates

1. Finish the fork CI checks and review the complete candidate against upstream and
   the currently deployed fork. Record the final commit, PHP/runtime requirements,
   built assets and Composer lock hash. Keep application and node identities intact.
2. Prepare an immutable panel artifact and deployment reference. The new release
   branch is separate from the old branch used by current bootstrap, so merging
   this PR does not select the upgrade for deployment. Platform PR 797 additionally
   pins the existing source against future changes to the old branch. Select the
   upgraded source/assets pair only as part of the coordinated rollout below.
   Do not use the upstream release tarball directly: it lacks the two API contracts.
3. Rehearse with an isolated database and the dev panel. Verify the installed Wings
   version against this Panel release, then test database-host registration,
   server ownership, SFTP, console and backup lifecycle. Do not assume the older
   compatibility table or branch name proves version compatibility.
4. Before deploying the additive backup migration, pause backup callers, drain old
   panel requests/queue jobs and old Hostvault workers, and take a recoverable panel
   database backup. Deploy dependencies, assets and migrations together; refresh
   caches and restart PHP/queue processes. This includes the Laravel 12 upgrade.
5. Deploy the dependent Hostvault worker only after the panel contract is verified.
   On a disposable server, test limits 1 and 3, failed and uncertain uploads,
   locked recovery points, worker interruption, completed OVH uploads, confirmed
   Minecraft autosave recovery and actual restores. Then resume intended schedules.
6. Promote the same reviewed artifact to production after dev acceptance, with a
   reviewed rollback path. The replacement migration refuses rollback while live
   source/candidate pairs remain; blindly reverting code or schema is not rollback.

No deployment, migration, privileged runtime inspection or game-node admission
change is performed by preparing this candidate or running CI.
