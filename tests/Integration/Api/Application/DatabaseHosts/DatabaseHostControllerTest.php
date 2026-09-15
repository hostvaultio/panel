<?php

namespace Pterodactyl\Tests\Integration\Api\Application\DatabaseHosts;

use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class DatabaseHostControllerTest extends ApplicationApiIntegrationTestCase
{
    private const ENDPOINT = '/api/application/database-hosts';

    public function testListAndViewNeverExposeCredentials(): void
    {
        $host = DatabaseHost::factory()->create();
        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.0.attributes.id', $host->id)
            ->assertJsonMissingPath('data.0.attributes.password');
        $this->getJson(self::ENDPOINT . '/' . $host->id)->assertOk()
            ->assertJsonPath('attributes.id', $host->id)
            ->assertJsonMissingPath('attributes.password');
    }

    public function testCreateVerifiesLocalDatabaseAndEncryptsPassword(): void
    {
        $connection = config('database.connections.mysql');
        $response = $this->postJson(self::ENDPOINT, [
            'name' => 'Isolated test host',
            'host' => $connection['host'],
            'port' => $connection['port'],
            'username' => $connection['username'],
            'password' => $connection['password'],
        ])->assertCreated()->assertJsonMissingPath('attributes.password');

        $host = DatabaseHost::query()->findOrFail($response->json('attributes.id'));
        $this->assertNotSame($connection['password'], $host->password);
        $this->assertSame($connection['password'], decrypt($host->password));
        $this->assertStringEndsWith('/database-hosts/' . $host->id, $response->json('meta.resource'));
        $this->deleteJson(self::ENDPOINT . '/' . $host->id)->assertNoContent();
        $this->assertDatabaseMissing('database_hosts', ['id' => $host->id]);
    }

    public function testCreateRequiresPasswordBeforeConnecting(): void
    {
        $before = DatabaseHost::query()->count();
        $this->postJson(self::ENDPOINT, [
            'name' => 'Invalid host', 'host' => '127.0.0.1', 'port' => 3306, 'username' => 'test',
        ])->assertUnprocessable();
        $this->assertSame($before, DatabaseHost::query()->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedEndpointProvider')]
    public function testApplicationKeyRequiresDatabaseHostPermission(int $permission, string $method, bool $specificHost): void
    {
        $host = DatabaseHost::factory()->create();
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_database_hosts' => $permission]);
        $endpoint = self::ENDPOINT . ($specificHost ? '/' . $host->id : '');
        // Each denial gets its own fixture: the panel exception handler rolls
        // back the test transaction, including the host and key it contains.
        $this->json($method, $endpoint)->assertForbidden();
    }

    public static function deniedEndpointProvider(): array
    {
        return [
            [AdminAcl::READ, 'POST', false],
            [AdminAcl::READ, 'DELETE', true],
            [AdminAcl::NONE, 'GET', false],
            [AdminAcl::NONE, 'GET', true],
        ];
    }

    public function testUnauthenticatedRequestCannotReadHosts(): void
    {
        $this->withHeader('Authorization', '')->getJson(self::ENDPOINT)->assertUnauthorized();
    }
}
