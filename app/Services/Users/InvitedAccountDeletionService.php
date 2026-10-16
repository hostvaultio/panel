<?php

namespace Pterodactyl\Services\Users;

use Pterodactyl\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\ConnectionInterface;

class InvitedAccountDeletionService
{
    public function __construct(private ConnectionInterface $connection, private UserDeletionService $deletion)
    {
    }

    public function handle(int $userId, int $inviterId): void
    {
        $this->connection->transaction(function () use ($userId, $inviterId) {
            // Billing-customer and provisioning-job writers acquire a shared user lock
            // before recording independent resources. FK-backed server/grant inserts
            // also wait for it. All resource reads are current (locking) reads.
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            abort_if($userId === $inviterId || $user->root_admin, 403);
            $invite = $this->connection->table('subuser_invites')->where('invitee_user_id', $userId)
                ->where('inviter_user_id', $inviterId)->lockForUpdate()->first();
            abort_unless($invite, 403);

            foreach (['servers' => 'owner_id', 'subusers' => 'user_id', 'billing_customers' => 'pterodactyl_user_id',
                'subscriptions' => 'pterodactyl_user_id', 'provisioning_jobs' => 'user_id'] as $table => $column) {
                // Missing platform tables must fail closed, never imply no resources.
                if ($this->connection->table($table)->where($column, $userId)->lockForUpdate()->first()) {
                    Log::info('invited_account_deletion_denied', ['actor_id' => $inviterId, 'user_id' => $userId, 'resource' => $table]);
                    abort(409, 'This account still has resources or access.');
                }
            }

            $this->deletion->handle($user);
        });
        Log::info('invited_account_deleted', ['actor_id' => $inviterId, 'user_id' => $userId]);
    }
}
