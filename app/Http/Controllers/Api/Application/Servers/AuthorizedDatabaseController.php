<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Databases\AuthorizedDatabaseService;
use Pterodactyl\Services\Databases\DatabaseManagementService;
use Pterodactyl\Http\Requests\Api\Application\Servers\Databases\GetServerDatabasesRequest;
use Pterodactyl\Http\Requests\Api\Application\Servers\Databases\ServerDatabaseWriteRequest;
use Pterodactyl\Http\Requests\Api\Application\Servers\Databases\StoreServerDatabaseRequest;

/** Dedicated routes fail closed on older Panels, which cannot enforce actor headers. */
class AuthorizedDatabaseController
{
    public function __construct(
        private AuthorizedDatabaseService $authorization,
        private DatabaseManagementService $databases,
        private Encrypter $encrypter,
    ) {
    }

    public function index(GetServerDatabasesRequest $request, Server $server): array
    {
        return $this->authorization->handle($request, $server, 'database.read', function (Server $locked, bool $password) {
            return ['object' => 'list', 'data' => $locked->databases->map(fn (Database $database) => $this->serialize($database, $password))->all()];
        });
    }

    public function store(StoreServerDatabaseRequest $request, Server $server): JsonResponse
    {
        // Keep creation's existing top-level transaction/compensation boundary.
        // Serialization after commit must not roll back metadata for a remote
        // database that was already created successfully.
        $password = false;
        $host = DatabaseHost::query()->findOrFail($request->validated()['database_host_id']);
        $database = $this->databases->create(
            $server,
            array_merge($request->validated(), ['database' => $request->databaseName()]),
            function (Server $locked) use ($request, &$password) {
                $password = $this->authorization->authorizeLocked($request, $locked, 'database.create');
            }
        );
        $database->setRelation('host', $host);

        return new JsonResponse($this->serialize($database, $password), Response::HTTP_CREATED);
    }

    public function delete(ServerDatabaseWriteRequest $request, Server $server, Database $database): Response
    {
        return $this->authorization->handle($request, $server, 'database.delete', function (Server $locked) use ($database) {
            $database = Database::query()->whereKey($database->id)->where('server_id', $locked->id)->lockForUpdate()->firstOrFail();
            $this->databases->delete($database);

            return response('', 204);
        });
    }

    /** Fixed customer projection: includes cannot traverse a host into other tenants' databases. */
    private function serialize(Database $database, bool $password): array
    {
        $host = $database->host;
        $relationships = ['host' => ['object' => 'database_host', 'attributes' => [
            'id' => $host->id, 'host' => $host->host, 'port' => $host->port,
        ]]];
        if ($password) {
            $relationships['password'] = ['object' => 'database_password', 'attributes' => [
                'password' => $this->encrypter->decrypt($database->password),
            ]];
        }

        return ['object' => Database::RESOURCE_NAME, 'attributes' => [
            'id' => $database->id,
            'server' => $database->server_id,
            'host' => $database->database_host_id,
            'database' => $database->database,
            'username' => $database->username,
            'remote' => $database->remote,
            'max_connections' => $database->max_connections,
            'created_at' => $database->created_at->toAtomString(),
            'updated_at' => $database->updated_at->toAtomString(),
            'relationships' => $relationships,
        ]];
    }
}
