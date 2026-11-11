<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Servers;

use Pterodactyl\Models\User;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Illuminate\Support\Facades\Notification;
use Illuminate\Database\Events\QueryExecuted;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class BillingSuspensionControllerTest extends IntegrationTestCase
{
    private Server $server;

    private int $tracking;

    private array $expected;

    public function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // These platform-owned tables are absent from Panel migrations. This
        // suite owns their fixture schema, never a live application database.
        foreach (['user_servers', 'subscriptions', 'billing_customers'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::create('subscriptions', function ($table) {
            $table->increments('id');
            $table->string('stripe_subscription_id')->unique();
            $table->string('stripe_customer_id');
            $table->unsignedInteger('pterodactyl_user_id')->nullable();
            $table->string('status');
            $table->boolean('cancel_at_period_end')->default(false);
        });
        Schema::create('billing_customers', function ($table) {
            $table->increments('id');
            $table->string('stripe_customer_id')->unique();
            $table->unsignedInteger('pterodactyl_user_id');
        });
        Schema::create('user_servers', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('pterodactyl_server_id');
            $table->unsignedInteger('pterodactyl_user_id');
            $table->string('stripe_subscription_id');
            $table->string('server_status');
        });
        $this->key();
        $this->server = $this->createServerModel();
        DB::table('subscriptions')->insert(['stripe_subscription_id' => 'sub_billing_fixture',
            'stripe_customer_id' => 'cus_billing_fixture', 'pterodactyl_user_id' => $this->server->owner_id, 'status' => 'past_due']);
        DB::table('billing_customers')->insert(['stripe_customer_id' => 'cus_billing_fixture', 'pterodactyl_user_id' => $this->server->owner_id]);
        $this->tracking = DB::table('user_servers')->insertGetId(['pterodactyl_server_id' => $this->server->id,
            'pterodactyl_user_id' => $this->server->owner_id, 'stripe_subscription_id' => 'sub_billing_fixture', 'server_status' => 'active']);
        $this->expected = ['action' => 'suspend', 'expected_owner_id' => $this->server->owner_id,
            'expected_tracking_id' => $this->tracking, 'expected_subscription_id' => 'sub_billing_fixture',
            'expected_customer_id' => 'cus_billing_fixture', 'expected_tracking_status' => 'active',
            'expected_subscription_status' => 'past_due', 'expected_cancel_at_period_end' => false];
    }

    protected function tearDown(): void
    {
        foreach (['user_servers', 'subscriptions', 'billing_customers'] as $name) {
            Schema::dropIfExists($name);
        }
        parent::tearDown();
    }

    private function key(int $permission = AdminAcl::WRITE): void
    {
        $user = User::factory()->create(['root_admin' => true]);
        $key = ApiKey::factory()->create(['user_id' => $user->id, 'key_type' => ApiKey::TYPE_APPLICATION, 'r_servers' => $permission]);
        $this->withHeader('Authorization', 'Bearer ' . $key->identifier . decrypt($key->token));
    }

    private function path(): string
    {
        return "/api/application/servers/{$this->server->id}/billing-suspension";
    }

    private function noWings(): void
    {
        $this->mock(DaemonServerRepository::class)->shouldNotReceive('setServer');
    }

    private function connection(): \PDO
    {
        $config = config('database.connections.mysql');
        $dsn = $config['unix_socket'] ? 'unix_socket=' . $config['unix_socket'] : 'host=' . $config['host'] . ';port=' . $config['port'];
        $pdo = new \PDO('mysql:' . $dsn . ';dbname=' . $config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $pdo;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('actions')]
    public function testCurrentIdentityReachesWingsAndLeavesPlatformTrackingToItsOwner(bool $unsuspend, bool $legacy): void
    {
        if ($unsuspend) {
            $this->server->update(['status' => Server::STATUS_SUSPENDED]);
            DB::table('user_servers')->where('id', $this->tracking)->update(['server_status' => 'suspended']);
            DB::table('subscriptions')->where('stripe_subscription_id', 'sub_billing_fixture')->update(['status' => 'active']);
            $this->expected = array_replace($this->expected, ['action' => 'unsuspend', 'expected_tracking_status' => 'suspended', 'expected_subscription_status' => 'active']);
        }
        if ($legacy) {
            DB::table('subscriptions')->where('stripe_subscription_id', 'sub_billing_fixture')->update(['pterodactyl_user_id' => null]);
        }
        $repository = $this->mock(DaemonServerRepository::class);
        $repository->shouldReceive('setServer')->once()->withArgs(fn (Server $server) => $server->id === $this->server->id)->andReturnSelf();
        $repository->shouldReceive('sync')->once();
        $this->postJson($this->path(), $this->expected)->assertNoContent();
        $this->assertSame(!$unsuspend, $this->server->refresh()->isSuspended());
        $this->assertDatabaseHas('user_servers', ['id' => $this->tracking, 'server_status' => $this->expected['expected_tracking_status']]);
    }

    public static function actions(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rebinds')]
    public function testChangedIdentityNeverReachesWings(string $change): void
    {
        $other = User::factory()->create();
        if ($change === 'owner') {
            $this->server->update(['owner_id' => $other->id]);
        } elseif ($change === 'tracking_owner') {
            DB::table('user_servers')->where('id', $this->tracking)->update(['pterodactyl_user_id' => $other->id]);
        } elseif ($change === 'tracking_server') {
            DB::table('user_servers')->where('id', $this->tracking)->update(['pterodactyl_server_id' => $this->server->id + 10000]);
        } elseif ($change === 'tracking_subscription') {
            DB::table('user_servers')->where('id', $this->tracking)->update(['stripe_subscription_id' => 'sub_other']);
        } elseif ($change === 'customer') {
            DB::table('subscriptions')->where('stripe_subscription_id', 'sub_billing_fixture')->update(['stripe_customer_id' => 'cus_other']);
        } elseif ($change === 'direct_owner') {
            DB::table('subscriptions')->where('stripe_subscription_id', 'sub_billing_fixture')->update(['pterodactyl_user_id' => $other->id]);
        } elseif ($change === 'mapped_owner') {
            DB::table('billing_customers')->where('stripe_customer_id', 'cus_billing_fixture')->update(['pterodactyl_user_id' => $other->id]);
        } elseif ($change === 'missing_customer') {
            DB::table('billing_customers')->delete();
        } elseif ($change === 'status') {
            DB::table('subscriptions')->update(['status' => 'active']);
        } else {
            DB::table('subscriptions')->update(['cancel_at_period_end' => true]);
        }
        $this->noWings();
        $this->postJson($this->path(), $this->expected)->assertStatus(409);
        $this->assertFalse($this->server->refresh()->isSuspended());
    }

    public static function rebinds(): array
    {
        return array_map(fn ($change) => [$change], ['owner', 'tracking_owner', 'tracking_server', 'tracking_subscription',
            'customer', 'direct_owner', 'mapped_owner', 'missing_customer', 'status', 'cancellation']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStates')]
    public function testTeardownCannotBeRewound(string $status): void
    {
        DB::table('user_servers')->where('id', $this->tracking)->update(['server_status' => $status]);
        $this->noWings();
        $this->postJson($this->path(), $this->expected)->assertStatus(409);
    }

    public static function terminalStates(): array
    {
        return [['deleting'], ['terminated']];
    }

    public function testUnsuspendRejectsCancellationEvenWhenTheExpectedSnapshotMatches(): void
    {
        DB::table('subscriptions')->update(['cancel_at_period_end' => true]);
        $this->expected = array_replace($this->expected, ['action' => 'unsuspend', 'expected_cancel_at_period_end' => true]);
        $this->noWings();
        $this->postJson($this->path(), $this->expected)->assertStatus(409);
    }

    public function testGuardFieldsAndApplicationWritePermissionAreMandatory(): void
    {
        $this->noWings();
        $this->postJson($this->path(), [])->assertStatus(422);
        $this->key(AdminAcl::READ);
        $this->postJson($this->path(), $this->expected)->assertForbidden();
    }

    public function testWingsFailurePreservesCommittedDesiredStatusAndRetriesSync(): void
    {
        $repository = $this->mock(DaemonServerRepository::class);
        $repository->shouldReceive('setServer')->twice()->andReturnSelf();
        $calls = 0;
        $repository->shouldReceive('sync')->twice()->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('Fixture Wings unavailable');
            }
        });
        $this->postJson($this->path(), $this->expected)->assertStatus(500);
        $this->assertTrue($this->server->refresh()->isSuspended());
        $pdo = $this->connection();
        $pdo->prepare('UPDATE user_servers SET server_status = ? WHERE id = ?')->execute(['active', $this->tracking]);
        $this->postJson($this->path(), $this->expected)->assertNoContent();
        $this->assertSame(2, $calls);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedRows')]
    public function testIdentityChangesWaitThroughTheActualWingsSync(string $table): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel(), 'Fixture rows must be committed, not hidden by a test transaction.');
        $other = User::factory()->create();
        $pdo = $this->connection();
        $this->assertSame((string) $this->server->owner_id, (string) $pdo->query("SELECT owner_id FROM servers WHERE id = {$this->server->id}")->fetchColumn());
        $changes = [
            'servers' => ['UPDATE servers SET owner_id = ? WHERE id = ?', [$other->id, $this->server->id]],
            'user_servers' => ['UPDATE user_servers SET server_status = ? WHERE id = ?', ['pending_deletion', $this->tracking]],
            'subscriptions' => ['UPDATE subscriptions SET cancel_at_period_end = ? WHERE stripe_subscription_id = ?', [1, 'sub_billing_fixture']],
            'billing_customers' => ['UPDATE billing_customers SET pterodactyl_user_id = ? WHERE stripe_customer_id = ?', [$other->id, 'cus_billing_fixture']],
        ];
        [$sql, $bindings] = $changes[$table];
        $repository = $this->mock(DaemonServerRepository::class);
        $repository->shouldReceive('setServer')->once()->andReturnSelf();
        $repository->shouldReceive('sync')->once()->andReturnUsing(function () use ($pdo, $sql, $bindings) {
            $this->assertSame(Server::STATUS_SUSPENDED, $pdo->query("SELECT status FROM servers WHERE id = {$this->server->id}")->fetchColumn(),
                'The configuration callback must see the committed desired status during sync.');
            try {
                $pdo->prepare($sql)->execute($bindings);
                $this->fail('A binding changed while Wings was syncing.');
            } catch (\PDOException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }
        });
        $this->postJson($this->path(), $this->expected)->assertNoContent();
        $pdo->prepare($sql)->execute($bindings);
        $this->postJson($this->path(), $this->expected)->assertStatus(409);
    }

    public static function protectedRows(): array
    {
        return [['servers'], ['user_servers'], ['subscriptions'], ['billing_customers']];
    }

    public function testATransferBetweenTheStatusCommitAndSyncPreventsTheWingsCommand(): void
    {
        $pdo = $this->connection();
        $other = User::factory()->create();
        $scheduled = false;
        DB::listen(function (QueryExecuted $query) use ($pdo, $other, &$scheduled) {
            if (!$scheduled && str_starts_with($query->sql, 'update `servers`')) {
                $scheduled = true;
                DB::afterCommit(function () use ($pdo, $other) {
                    $pdo->prepare('UPDATE servers SET owner_id = ? WHERE id = ?')->execute([$other->id, $this->server->id]);
                });
            }
        });
        $this->noWings();
        $this->postJson($this->path(), $this->expected)->assertStatus(409);
        $this->assertTrue($scheduled);
        $this->assertSame($other->id, $this->server->refresh()->owner_id);
        // The old owner's desired status committed while authorized. Do not undo it.
        $this->assertTrue($this->server->isSuspended());
    }

    public function testAChangeAfterRouteBindingWinsBeforeTheAuthorizationLock(): void
    {
        $pdo = $this->connection();
        $other = User::factory()->create();
        $moved = false;
        DB::listen(function (QueryExecuted $query) use ($pdo, $other, &$moved) {
            if (!$moved && str_starts_with($query->sql, 'select') && str_contains($query->sql, '`servers`') && !str_contains($query->sql, 'for update')) {
                $moved = true;
                $pdo->prepare('UPDATE servers SET owner_id = ? WHERE id = ?')->execute([$other->id, $this->server->id]);
            }
        });
        $this->noWings();
        $this->postJson($this->path(), $this->expected)->assertStatus(409);
        $this->assertTrue($moved);
    }
}
