<?php

namespace Pterodactyl\Http\Controllers\Api\Application\DatabaseHosts;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\DatabaseHost;
use Spatie\QueryBuilder\QueryBuilder;
use Pterodactyl\Services\Databases\Hosts\HostCreationService;
use Pterodactyl\Services\Databases\Hosts\HostDeletionService;
use Pterodactyl\Transformers\Api\Application\DatabaseHostTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Http\Requests\Api\Application\DatabaseHosts\GetDatabaseHostRequest;
use Pterodactyl\Http\Requests\Api\Application\DatabaseHosts\GetDatabaseHostsRequest;
use Pterodactyl\Http\Requests\Api\Application\DatabaseHosts\StoreDatabaseHostRequest;
use Pterodactyl\Http\Requests\Api\Application\DatabaseHosts\DeleteDatabaseHostRequest;

class DatabaseHostController extends ApplicationApiController
{
    /**
     * DatabaseHostController constructor.
     */
    public function __construct(
        private HostCreationService $creationService,
        private HostDeletionService $deletionService,
    ) {
        parent::__construct();
    }

    /**
     * Return all the database hosts currently registered on the Panel.
     */
    public function index(GetDatabaseHostsRequest $request): array
    {
        $hosts = QueryBuilder::for(DatabaseHost::query())
            ->allowedFilters(['name', 'host'])
            ->allowedSorts(['id', 'name', 'host'])
            ->paginate($request->query('per_page') ?? 50);

        return $this->fractal->collection($hosts)
            ->transformWith($this->getTransformer(DatabaseHostTransformer::class))
            ->toArray();
    }

    /**
     * Return a single database host.
     */
    public function view(GetDatabaseHostRequest $request, DatabaseHost $databaseHost): array
    {
        return $this->fractal->item($databaseHost)
            ->transformWith($this->getTransformer(DatabaseHostTransformer::class))
            ->toArray();
    }

    /**
     * Store a new database host on the Panel and return an HTTP/201 response code with
     * the new host attached. The credentials are verified against the target server
     * before the record is persisted.
     *
     * @throws \Throwable
     */
    public function store(StoreDatabaseHostRequest $request): JsonResponse
    {
        $host = $this->creationService->handle($request->validated());

        return $this->fractal->item($host)
            ->transformWith($this->getTransformer(DatabaseHostTransformer::class))
            ->addMeta([
                'resource' => route('api.application.database_hosts.view', [
                    'databaseHost' => $host->id,
                ]),
            ])
            ->respond(201);
    }

    /**
     * Delete a database host from the Panel. Fails if any databases are still
     * attached to the host.
     *
     * @throws \Pterodactyl\Exceptions\Service\HasActiveServersException
     */
    public function delete(DeleteDatabaseHostRequest $request, DatabaseHost $databaseHost): Response
    {
        $this->deletionService->handle($databaseHost->id);

        return response('', 204);
    }
}
