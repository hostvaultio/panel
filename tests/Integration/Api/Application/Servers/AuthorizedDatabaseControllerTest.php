<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Servers;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Repositories\Eloquent\DatabaseRepository;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class AuthorizedDatabaseControllerTest extends ApplicationApiIntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actor(int $actor, int $owner): void
    {
        $this->withHeader('X-Hostvault-Actor-Id', (string) $actor)->withHeader('X-Hostvault-Owner-Id', (string) $owner);
    }

    public function testReadOnlyGrantCannotExposePasswordsOrTraverseOtherHostDatabases(): void
    {
        [$user, $server] = $this->generateTestAccount(['database.read']);
        $database = Database::factory()->create(['database_host_id' => DatabaseHost::factory()->create()->id, 'server_id' => $server->id]);
        $other = $this->createServerModel();
        $foreign = Database::factory()->create(['server_id' => $other->id, 'database_host_id' => $database->database_host_id]);
        $this->actor($user->id, $server->owner_id);
        $this->getJson("/api/application/servers/{$server->id}/authorized-databases?include=host.databases.password,password")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.attributes.id', $database->id)
            ->assertJsonMissingPath('data.0.attributes.relationships.password')
            ->assertJsonMissingPath('data.0.attributes.relationships.host.attributes.username')
            ->assertJsonMissingPath('data.0.attributes.relationships.host.attributes.relationships')
            ->assertJsonMissing(['database' => $foreign->database]);
    }

    public function testOwnerReceivesCredentials(): void
    {
        $server = $this->createServerModel();
        Database::factory()->create(['database_host_id' => DatabaseHost::factory()->create()->id, 'server_id' => $server->id, 'password' => encrypt('fixture-secret')]);
        $this->actor($server->owner_id, $server->owner_id);
        $this->getJson("/api/application/servers/{$server->id}/authorized-databases")
            ->assertOk()->assertJsonPath('data.0.attributes.relationships.password.attributes.password', 'fixture-secret');
    }

    public function testCreateOnlyGrantCreatesWithoutReadingCredentials(): void
    {
        config()->set('pterodactyl.client_features.databases.enabled', true);
        [$user, $server] = $this->generateTestAccount(['database.create']);
        $server->forceFill(['database_limit' => 1])->save();
        $host = DatabaseHost::factory()->create();
        $dynamic = \Mockery::mock(DynamicDatabaseConnection::class);
        $dynamic->shouldReceive('set')->once();
        $this->app->instance(DynamicDatabaseConnection::class, $dynamic);
        $repository = \Mockery::mock(DatabaseRepository::class);
        foreach (['createDatabase', 'createUser', 'assignUserToDatabase', 'flush'] as $method) {
            $repository->shouldReceive($method)->once();
        }
        $this->app->instance(DatabaseRepository::class, $repository);
        $this->actor($user->id, $server->owner_id);
        $this->postJson("/api/application/servers/{$server->id}/authorized-databases?include=password", ['database' => 'fixture', 'remote' => '%', 'host' => $host->id])
            ->assertCreated()->assertJsonPath('attributes.server', $server->id)->assertJsonMissingPath('attributes.relationships.password');
        $this->assertDatabaseHas('databases', ['server_id' => $server->id, 'database' => "s{$server->id}_fixture"]);
    }

    public function testQuotaStillRejectsBeforeRemoteDatabaseCreation(): void
    {
        config()->set('pterodactyl.client_features.databases.enabled', true);
        $server = $this->createServerModel(['database_limit' => 0]);
        $host = DatabaseHost::factory()->create();
        $dynamic = \Mockery::mock(DynamicDatabaseConnection::class);
        $dynamic->shouldNotReceive('set');
        $this->app->instance(DynamicDatabaseConnection::class, $dynamic);
        $this->actor($server->owner_id, $server->owner_id);
        $this->postJson("/api/application/servers/{$server->id}/authorized-databases", ['database' => 'fixture', 'remote' => '%', 'host' => $host->id])
            ->assertStatus(400)->assertJsonPath('errors.0.code', 'TooManyDatabasesException');
    }

    public function testForeignChildCannotBeDeleted(): void
    {
        $server = $this->createServerModel();
        $other = $this->createServerModel();
        $database = Database::factory()->create(['database_host_id' => DatabaseHost::factory()->create()->id, 'server_id' => $other->id]);
        $dynamic = \Mockery::mock(DynamicDatabaseConnection::class);
        $dynamic->shouldNotReceive('set');
        $this->app->instance(DynamicDatabaseConnection::class, $dynamic);
        $this->actor($server->owner_id, $server->owner_id);
        $this->deleteJson("/api/application/servers/{$server->id}/authorized-databases/{$database->id}")->assertNotFound();
    }

    public function testUnrelatedActorCannotUsePrivilegedKeyToReadServer(): void
    {
        $server = $this->createServerModel();
        $this->actor(User::factory()->create()->id, $server->owner_id);
        $this->getJson("/api/application/servers/{$server->id}/authorized-databases")->assertNotFound();
    }

    public function testActorHeadersAreMandatory(): void
    {
        $server = $this->createServerModel();
        $this->getJson("/api/application/servers/{$server->id}/authorized-databases")->assertStatus(400);
    }

    public function testDeleteOnlyGrantCanDeleteItsOwnServerDatabase(): void
    {
        [$user, $server] = $this->generateTestAccount(['database.delete']);
        $database = Database::factory()->create(['server_id' => $server->id, 'database_host_id' => DatabaseHost::factory()->create()->id]);
        $dynamic = \Mockery::mock(DynamicDatabaseConnection::class);
        $dynamic->shouldReceive('set')->once();
        $this->app->instance(DynamicDatabaseConnection::class, $dynamic);
        $repository = \Mockery::mock(DatabaseRepository::class);
        $repository->shouldReceive('dropDatabase')->once()->with($database->database);
        $repository->shouldReceive('dropUser')->once()->with($database->username, $database->remote);
        $repository->shouldReceive('flush')->once();
        $this->app->instance(DatabaseRepository::class, $repository);
        $this->actor($user->id, $server->owner_id);
        $this->deleteJson("/api/application/servers/{$server->id}/authorized-databases/{$database->id}")->assertNoContent();
        $this->assertModelMissing($database);
    }

    public function testApplicationKeyStillRequiresDatabasePermission(): void
    {
        $server = $this->createServerModel();
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_server_databases' => 0]);
        $this->actor($server->owner_id, $server->owner_id);
        $this->getJson("/api/application/servers/{$server->id}/authorized-databases")->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staleCreateRequests')]
    public function testStaleCreateAuthorizationNeverReachesRemoteSql(bool $transfer): void
    {
        config()->set('pterodactyl.client_features.databases.enabled', true);
        [$user, $server] = $this->generateTestAccount(['database.create']);
        $owner = $server->owner_id;
        if ($transfer) {
            $server->forceFill(['owner_id' => User::factory()->create()->id])->save();
        } else {
            \Pterodactyl\Models\Subuser::query()->where('server_id', $server->id)->where('user_id', $user->id)->update(['permissions' => ['database.read']]);
        }
        $host = DatabaseHost::factory()->create();
        $dynamic = \Mockery::mock(DynamicDatabaseConnection::class);
        $dynamic->shouldNotReceive('set');
        $this->app->instance(DynamicDatabaseConnection::class, $dynamic);
        $this->actor($user->id, $owner);
        $this->postJson("/api/application/servers/{$server->id}/authorized-databases", ['database' => 'fixture', 'remote' => '%', 'host' => $host->id])->assertNotFound();
    }

    public static function staleCreateRequests(): array
    {
        return [[true], [false]];
    }
}
