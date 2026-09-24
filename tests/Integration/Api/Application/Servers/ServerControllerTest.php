<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Servers;

use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class ServerControllerTest extends ApplicationApiIntegrationTestCase
{
    public function testFeatureOnlyBuildUpdatePreservesHardwareAndOomPolicy(): void
    {
        $hardware = ['memory' => 4096, 'swap' => 0, 'disk' => 61440, 'io' => 500,
            'cpu' => 200, 'threads' => '0-1', 'oom_disabled' => true];
        $server = $this->createServerModel(array_merge($hardware, ['subuser_limit' => 3]));
        $this->mock(DaemonServerRepository::class)->shouldReceive('setServer->sync')->andReturnUndefined();

        $this->patchJson('/api/application/servers/' . $server->id . '/build', [
            'allocation' => $server->allocation_id,
            'feature_limits' => ['databases' => 2, 'backups' => 3, 'allocations' => 2, 'subusers' => 5],
        ])->assertOk()->assertJsonPath('attributes.feature_limits.subusers', 5);

        $server->refresh();
        foreach ($hardware as $key => $value) {
            $this->assertSame($value, $server->{$key});
        }
        $this->assertSame(5, $server->subuser_limit);
    }

    public function testFeatureUpdateStillValidatesProvidedHardware(): void
    {
        $server = $this->createServerModel();
        $payload = [
            'allocation' => $server->allocation_id,
            'feature_limits' => ['databases' => 2, 'backups' => 3, 'allocations' => 2, 'subusers' => 5],
        ];
        $this->patchJson('/api/application/servers/' . $server->id . '/build', array_merge($payload, [
            'memory' => -1,
        ]))->assertUnprocessable();
        $this->patchJson('/api/application/servers/' . $server->id . '/build', array_merge($payload, [
            'limits' => ['memory' => 4096],
        ]))->assertUnprocessable();
    }

    /**
     * Test that the "skip scripts" state is returned for a server.
     */
    public function testSkipScriptsStateIsReturned()
    {
        $server = $this->createServerModel(['skip_scripts' => true]);

        $this->getJson('/api/application/servers/' . $server->id)
            ->assertOk()
            ->assertJsonPath('attributes.container.skip_scripts', true);

        $server->update(['skip_scripts' => false]);

        $this->getJson('/api/application/servers/' . $server->id)
            ->assertOk()
            ->assertJsonPath('attributes.container.skip_scripts', false);
    }
}
