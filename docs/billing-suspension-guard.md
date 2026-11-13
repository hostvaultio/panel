# Guarded billing suspension

Tracking: `platform-1qo8.4` in `hostvaultio/platform`.

`POST /api/application/servers/{id}/billing-suspension` requires the existing
application-key server-write permission and an action (`suspend` or `unsuspend`).
It also requires expected owner/tracking IDs, subscription/customer IDs, tracking
status, subscription status and cancellation flag. Missing fields are rejected;
a changed identity/state returns 409 before a Wings command. The platform client
must never treat a missing endpoint as success or fall back to an unguarded route.

Each phase locks the current canonical server for update, then the exact tracking
row, subscription and billing-customer row for sharing. Current Panel owner,
tracking user and customer-mapped owner must agree with the expected owner. A
non-null direct subscription owner must also agree; legacy customer-bound rows
remain valid. A missing customer row fails closed because there is no identity
row to hold locked. Terminal tracking states are rejected. Unsuspend rejects
cancellation; suspend requires active tracking. No platform auxiliary row is written.

Wings sync fetches configuration from Panel through a separate HTTP request.
Therefore the desired Panel status commits in the first guarded transaction.
A second transaction rereads all identities/state and checks the committed desired
status, then holds those locks through sync. This phase performs no SQL writes,
so the configuration callback sees committed status. A transfer/rebind winning
between phases prevents the command. A transfer during sync waits for its locks.
The current locked model replaces the earlier route-binding snapshot.

A failed/uncertain sync leaves committed desired status intact. Retry reauthorizes
and always syncs, including when Panel already has that status. There is no blind
inverse command or database status rollback, and neither transaction automatically
retries. Panel status and Wings are not one distributed commit. An authorized
status can have committed before a later transfer prevents sync; reconcile that
state explicitly under the new identity. Binding/state changes that return to the
same values and out-of-order billing events are not versioned by this contract.
Other server lifecycle/content routes and existing console/SFTP sessions remain
separate work.

The contract suite uses real HTTP request handling and MySQL with controlled Wings.
Independent PDO connections prove committed status visibility during sync, lock
contention for each identity row, and lock release; route-binding and between-phase
transfers verify fresh reads. Other cases cover legacy binding, stale snapshots,
terminal/cancel rejection, required fields/application permission and sync retry.
These tests do not establish live AWS/OVH acceptance.

Merge/build this Panel change, deploy and accept it in dev before enabling the
platform caller. Verify old processes drained and exercise allowed, stale-owner,
rebound-customer, terminal and failed-sync/retry cases. Advance account-service's
accepted artifact digest and readiness receipt only after that bundle is verified.
Promote the same accepted bytes to production under its separate authorization.
Keep application deployments gated while Panel readiness is revoked or unproven.
No migration, dependency or configuration change is introduced by this endpoint.
