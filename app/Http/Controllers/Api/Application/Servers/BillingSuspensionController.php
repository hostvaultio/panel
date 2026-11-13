<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\BillingSuspensionService;
use Pterodactyl\Http\Requests\Api\Application\Servers\BillingSuspensionRequest;

class BillingSuspensionController
{
    public function __construct(private BillingSuspensionService $suspension)
    {
    }

    public function store(BillingSuspensionRequest $request, Server $server): Response
    {
        $this->suspension->handle($server, $request->validated());

        return response('', 204);
    }
}
