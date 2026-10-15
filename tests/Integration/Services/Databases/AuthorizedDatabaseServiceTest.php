<?php

namespace Pterodactyl\Tests\Integration\Services\Databases;

use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Subuser;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Services\Databases\AuthorizedDatabaseService;

class AuthorizedDatabaseServiceTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function request(int $actor, int $owner): Request
    {
        return Request::create('/', 'GET', [], [], [], ['HTTP_X_HOSTVAULT_ACTOR_ID' => (string) $actor, 'HTTP_X_HOSTVAULT_OWNER_ID' => (string) $owner]);
    }

    private function connection(): \PDO
    {
        $config = config('database.connections.mysql');
        $dsn = $config['unix_socket'] ? 'unix_socket=' . $config['unix_socket'] : 'host=' . $config['host'] . ';port=' . $config['port'];
        $pdo = new \PDO('mysql:' . $dsn . ';dbname=' . $config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $pdo;
    }

    private function assertBlocked(\PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
            $this->fail('A concurrent ownership/grant change escaped the authorization lock.');
        } catch (\PDOException $exception) {
            $this->assertSame(1205, $exception->errorInfo[1]);
        }
    }

    public function testOwnershipCannotChangeDuringOperationAndStaleOwnerIsDeniedAfterTransfer(): void
    {
        $server = $this->createServerModel();
        $next = User::factory()->create();
        $pdo = $this->connection();
        $sql = "UPDATE servers SET owner_id = {$next->id} WHERE id = {$server->id}";
        $service = $this->app->make(AuthorizedDatabaseService::class);
        $request = $this->request($server->owner_id, $server->owner_id);
        $service->handle($request, $server, 'database.create', function ($locked, $password) use ($pdo, $sql) {
            $this->assertTrue($password);
            $this->assertBlocked($pdo, $sql);
        });
        $pdo->exec($sql);
        try {
            $service->handle($request, $server, 'database.create', fn () => $this->fail('Stale authorization executed an operation.'));
            $this->fail('Former owner was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('revocations')]
    public function testGrantCannotChangeDuringOperationAndRevocationWinsBeforeNextOperation(string $change): void
    {
        [$user, $server] = $this->generateTestAccount(['database.delete', 'database.view_password']);
        $grant = Subuser::query()->where('server_id', $server->id)->where('user_id', $user->id)->firstOrFail();
        $pdo = $this->connection();
        $sql = $change === 'delete' ? "DELETE FROM subusers WHERE id = {$grant->id}"
            : "UPDATE subusers SET permissions = '[]' WHERE id = {$grant->id}";
        $service = $this->app->make(AuthorizedDatabaseService::class);
        $request = $this->request($user->id, $server->owner_id);
        $service->handle($request, $server, 'database.delete', function ($locked, $password) use ($pdo, $sql) {
            $this->assertTrue($password);
            $this->assertBlocked($pdo, $sql);
        });
        $pdo->exec($sql);
        try {
            $service->handle($request, $server, 'database.delete', fn () => $this->fail('Revoked user executed an operation.'));
            $this->fail('Revoked user was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public static function revocations(): array
    {
        return [['delete'], ['permissions']];
    }
}
