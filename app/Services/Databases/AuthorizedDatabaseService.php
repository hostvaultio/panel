<?php

namespace Pterodactyl\Services\Databases;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\ConnectionInterface;

/** Final authorization and the operation share the locks that protect ownership and grants. */
class AuthorizedDatabaseService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function handle(Request $request, Server $server, string $permission, \Closure $operation): mixed
    {
        // No retries: the operation can delete a database on another host.
        return $this->connection->transaction(function () use ($request, $server, $permission, $operation) {
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            return $operation($locked, $this->authorizeLocked($request, $locked, $permission));
        });
    }

    /** The caller must hold the server row lock in the mutation's transaction. */
    public function authorizeLocked(Request $request, Server $server, string $permission): bool
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new \LogicException('Resource authorization requires a transaction.');
        }
        $actor = filter_var($request->header('X-Hostvault-Actor-Id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $owner = filter_var($request->header('X-Hostvault-Owner-Id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless($actor && $owner, 400, 'An actor and expected owner are required.');
        $permissions = [];
        if ($server->owner_id === $owner && $actor !== $owner) {
            $subuser = Subuser::query()->where('server_id', $server->id)
                ->where('user_id', $actor)->lockForUpdate()->first();
            $permissions = $subuser?->permissions ?? [];
        }

        if ($server->owner_id !== $owner || ($actor !== $owner && !in_array($permission, $permissions, true))) {
            Log::info('resource_authorization_denied', ['actor_id' => $actor, 'server_id' => $server->id, 'permission' => $permission]);
            abort(404);
        }

        return $actor === $owner || in_array('database.view_password', $permissions, true);
    }
}
