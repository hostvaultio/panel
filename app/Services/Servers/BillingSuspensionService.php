<?php

namespace Pterodactyl\Services\Servers;

use Webmozart\Assert\Assert;
use Pterodactyl\Models\Server;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/** Authorize billing identity at the backend that sends the Wings command. */
class BillingSuspensionService
{
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemon,
    ) {
    }

    public function handle(Server $server, array $expected): void
    {
        Assert::oneOf($expected['action'], [SuspensionService::ACTION_SUSPEND, SuspensionService::ACTION_UNSUSPEND]);
        $status = $expected['action'] === SuspensionService::ACTION_SUSPEND ? Server::STATUS_SUSPENDED : null;
        // Wings fetches configuration over a separate Panel request. Commit the
        // desired status first so that request cannot read the old status.
        $this->withIdentity($server, $expected, function (Server $locked) use ($status) {
            abort_if(!is_null($locked->transfer), 409, 'Server is being transferred.');
            if ($locked->status !== $status) {
                $locked->update(['status' => $status]);
            }
        });

        // Recheck after commit: a transfer/rebind may have won between phases.
        // Keep this phase read-only so configuration reads see committed state.
        $this->withIdentity($server, $expected, function (Server $locked) use ($status) {
            abort_unless($locked->status === $status && is_null($locked->transfer), 409, 'Server state changed.');
            // Always sync, including retries whose desired status already committed.
            // A failed/uncertain sync must not blindly roll back a newer state.
            $this->daemon->setServer($locked)->sync();
        });
    }

    private function withIdentity(Server $server, array $expected, \Closure $operation): void
    {
        // Never retry a transaction that may already have sent a Wings command.
        $this->connection->transaction(function () use ($server, $expected, $operation) {
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $owner = (int) $expected['expected_owner_id'];
            abort_unless($locked->owner_id === $owner, 409, 'Billing server identity changed.');

            $tracking = $this->connection->table('user_servers')
                ->where('id', $expected['expected_tracking_id'])
                ->where('pterodactyl_server_id', $locked->id)
                ->where('stripe_subscription_id', $expected['expected_subscription_id'])
                ->sharedLock()->first();
            abort_unless($tracking && (int) $tracking->pterodactyl_user_id === $owner
                && $tracking->stripe_subscription_id === $expected['expected_subscription_id']
                && $tracking->server_status === $expected['expected_tracking_status'], 409, 'Billing server identity changed.');

            $subscription = $this->connection->table('subscriptions')
                ->where('stripe_subscription_id', $expected['expected_subscription_id'])->sharedLock()->first();
            abort_unless(
                $subscription
                && $subscription->stripe_subscription_id === $expected['expected_subscription_id']
                && $subscription->stripe_customer_id === $expected['expected_customer_id']
                && $subscription->status === $expected['expected_subscription_status']
                && (bool) $subscription->cancel_at_period_end === (bool) $expected['expected_cancel_at_period_end'],
                409,
                'Billing subscription identity changed.'
            );

            $customer = $this->connection->table('billing_customers')
                ->where('stripe_customer_id', $subscription->stripe_customer_id)->sharedLock()->first();
            // A real customer row is required so its identity remains locked through sync.
            abort_unless(
                $customer && $customer->stripe_customer_id === $subscription->stripe_customer_id
                && (int) $customer->pterodactyl_user_id === $owner
                && ($subscription->pterodactyl_user_id === null || (int) $subscription->pterodactyl_user_id === $owner),
                409,
                'Billing customer identity changed.'
            );

            $action = $expected['action'];
            abort_unless(in_array($tracking->server_status, ['active', 'suspended', 'pending_deletion'], true), 409);
            if ($action === SuspensionService::ACTION_UNSUSPEND) {
                abort_if((bool) $subscription->cancel_at_period_end, 409, 'Subscription is scheduled to cancel.');
            } else {
                abort_unless($tracking->server_status === 'active', 409, 'Server is no longer active.');
            }

            // Use the current locked model, not the earlier route-binding snapshot.
            $operation($locked);
        }, 1);
    }
}
