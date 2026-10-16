# Hostvault guarded resource API

The Application API remains privileged. These additional routes require its usual
API key ACL and a trusted actor header from account-service. They are separate
paths so clients cannot silently use an older Panel that ignores actor headers.

| Method and path (below `/api/application`) | Required actor permission |
| --- | --- |
| GET `/servers/{id}/authorized-databases` | Owner or `database.read` |
| POST `/servers/{id}/authorized-databases` | Owner or `database.create` |
| DELETE `/servers/{id}/authorized-databases/{database}` | Owner or `database.delete` |
| DELETE `/users/{id}/invited-account` | Original inviter; orphaned account only |

Database requests require positive integer `X-Hostvault-Actor-Id` and
`X-Hostvault-Owner-Id` headers. The owner header is the expected owner from the
platform's preflight, not a substitute for the current Panel owner. The Panel locks
the current server and applicable subuser grant, rechecks authorization and holds
the locks through each mutation or list-response serialization. Creation checks
password permission in its mutation transaction and serializes the committed result
without further database reads; response failure must not remove committed metadata. A stale owner or
missing/revoked permission returns 404. Child database IDs are scoped to the locked
server. Passwords require owner access or `database.view_password`. Responses have
a fixed projection: arbitrary `include` parameters cannot follow a host to another
customer's databases, and host administrator credentials are excluded.

POST uses the usual database/remote/host body and the existing atomic database quota
service. No transaction retry is added around remote SQL side effects. Existing
remote SQL compensation/reconciliation limits still apply.

Invited-account deletion requires `X-Hostvault-Actor-Id`. The target cannot be the
actor or an administrator. It must have an invite from that actor, no owned servers,
no subuser grants and no billing customer, subscription or provisioning job. The
route locks the target user and reads all eligibility records using current locking
reads before deleting it. Resource conflicts return 409. Missing platform tables
cause failure, not deletion. Successful deletion is logged after commit.

The platform must take a shared `users` row lock and verify existence before
recording a billing customer or provisioning job. This conflicts with deletion's
exclusive lock, including when those platform tables have no user foreign key.
Server/grant foreign keys also coordinate their creation with deletion. These
platform tables must already exist: `subuser_invites`, `billing_customers`,
`subscriptions`, and `provisioning_jobs`.

Deploy this Panel release first, verify its routes, then deploy the companion
platform change (platform-uc36). No new migration, dependency or frontend build is
required by this source change. Use the normal complete Panel release artifact.
Merging the branch does not deploy a running host. Older Panel releases return 404
and the platform must not fall back to general privileged routes or interpret the
missing route as a successful DELETE.

Local validation: PHP 8.3, isolated MySQL 8.4 with networking disabled; PHPUnit
`--filter 'AuthorizedDatabase|InvitedAccountDeletion'`. Tests use independent PDO
connections to observe blocking ownership/grant changes, actual Panel API routing,
permissions, child scoping, safe response projection and account eligibility. Only
remote game-database SQL calls and notifications are faked. Platform SQL tests
exercise its writers with restricted grants and actual InnoDB lock waits.

This does not revoke previously disclosed credentials, active console/SFTP sessions
or signed URLs. Other privileged platform operations still need separate atomic
authorization work. Privileged operator APIs remain an explicit trusted boundary.
