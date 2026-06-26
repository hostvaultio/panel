<?php

namespace Pterodactyl\Http\Requests\Api\Application\DatabaseHosts;

use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

class StoreDatabaseHostRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_DATABASE_HOSTS;

    protected int $permission = AdminAcl::WRITE;

    /**
     * Rules to validate the request against. Reuses the model rules for the
     * shared fields and always requires a password (which the creation service
     * encrypts before persisting).
     */
    public function rules(): array
    {
        return collect(DatabaseHost::getRules())
            ->only(['name', 'host', 'port', 'username', 'node_id'])
            ->merge(['password' => 'required|string'])
            ->toArray();
    }

    /**
     * Rename fields to be more clear in error messages.
     */
    public function attributes(): array
    {
        return [
            'name' => 'Database Host Name',
            'host' => 'Database Host Address',
            'node_id' => 'Linked Node',
        ];
    }
}
