<?php

namespace Pterodactyl\Tests\Integration\Services\Users;

use Pterodactyl\Models\User;
use Pterodactyl\Models\ApiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Services\Users\UserDeletionService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Services\Users\InvitedAccountDeletionService;

class InvitedAccountDeletionServiceTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Platform-owned tables are not part of the Panel's migrations.
        foreach (['subuser_invites' => ['invitee_user_id', 'inviter_user_id'],
            'billing_customers' => ['pterodactyl_user_id'], 'subscriptions' => ['pterodactyl_user_id'],
            'provisioning_jobs' => ['user_id']] as $name => $columns) {
            if (!Schema::hasTable($name)) {
                Schema::create($name, function ($table) use ($columns) {
                    $table->increments('id');
                    foreach ($columns as $column) {
                        $table->unsignedInteger($column)->index();
                    }
                });
            }
        }
    }

    private function invite(): array
    {
        $inviter = User::factory()->create();
        $target = User::factory()->create();
        DB::table('subuser_invites')->insert(['invitee_user_id' => $target->id, 'inviter_user_id' => $inviter->id]);

        return [$target, $inviter];
    }

    public function testDeletesOnlyAnOrphanInvitedByTheActor(): void
    {
        [$target, $inviter] = $this->invite();
        $this->app->make(InvitedAccountDeletionService::class)->handle($target->id, $inviter->id);
        $this->assertModelMissing($target);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('independentResources')]
    public function testCommittedIndependentResourcePreventsDeletion(string $table, string $column): void
    {
        [$target, $inviter] = $this->invite();
        DB::table($table)->insert([$column => $target->id]);
        try {
            $this->app->make(InvitedAccountDeletionService::class)->handle($target->id, $inviter->id);
            $this->fail('An independent account was deleted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertModelExists($target);
        }
    }

    public static function independentResources(): array
    {
        return [['billing_customers', 'pterodactyl_user_id'], ['subscriptions', 'pterodactyl_user_id'], ['provisioning_jobs', 'user_id']];
    }

    public function testAnUnrelatedInviterIsDenied(): void
    {
        [$target] = $this->invite();
        try {
            $this->app->make(InvitedAccountDeletionService::class)->handle($target->id, User::factory()->create()->id);
            $this->fail('An unrelated actor was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertModelExists($target);
        }
    }

    public function testUserLockIsHeldThroughFinalDeletion(): void
    {
        [$target, $inviter] = $this->invite();
        $config = config('database.connections.mysql');
        $dsn = $config['unix_socket'] ? 'unix_socket=' . $config['unix_socket'] : 'host=' . $config['host'] . ';port=' . $config['port'];
        $pdo = new \PDO('mysql:' . $dsn . ';dbname=' . $config['database'], $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $deletion = \Mockery::mock(UserDeletionService::class);
        $deletion->shouldReceive('handle')->once()->andReturnUsing(function (User $locked) use ($pdo, $target) {
            try {
                // The platform's billing/job writers use this exact user-row lock.
                $pdo->query("SELECT id FROM users WHERE id = {$target->id} FOR UPDATE");
                $this->fail('A writer acquired the user before deletion finished.');
            } catch (\PDOException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }

            return $locked->delete();
        });
        (new InvitedAccountDeletionService(DB::connection(), $deletion))->handle($target->id, $inviter->id);
        $this->assertFalse($pdo->query("SELECT id FROM users WHERE id = {$target->id} FOR UPDATE")->fetch());
    }

    public function testOwnedServerPreventsDeletion(): void
    {
        [$target, $inviter] = $this->invite();
        $this->createServerModel(['owner_id' => $target->id]);
        try {
            $this->app->make(InvitedAccountDeletionService::class)->handle($target->id, $inviter->id);
            $this->fail('Server owner was deleted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    private function applicationKey(int $permission = 3): void
    {
        $admin = User::factory()->create(['root_admin' => true]);
        $key = ApiKey::factory()->create(['user_id' => $admin->id, 'key_type' => ApiKey::TYPE_APPLICATION, 'r_users' => $permission]);
        $this->withHeader('Authorization', 'Bearer ' . $key->identifier . decrypt($key->token));
    }

    public function testGuardedRouteDeletesAnInvitedOrphan(): void
    {
        [$target, $inviter] = $this->invite();
        $this->applicationKey();
        $this->withHeader('X-Hostvault-Actor-Id', (string) $inviter->id)
            ->deleteJson("/api/application/users/{$target->id}/invited-account")->assertNoContent();
        $this->assertModelMissing($target);
    }

    public function testGuardedRouteRequiresActor(): void
    {
        [$target] = $this->invite();
        $this->applicationKey();
        $this->deleteJson("/api/application/users/{$target->id}/invited-account")->assertStatus(400);
        $this->assertModelExists($target);
    }

    public function testGuardedRouteStillRequiresApplicationWritePermission(): void
    {
        [$target, $inviter] = $this->invite();
        $this->applicationKey(AdminAcl::READ);
        $this->withHeader('X-Hostvault-Actor-Id', (string) $inviter->id)
            ->deleteJson("/api/application/users/{$target->id}/invited-account")->assertForbidden();
        $this->assertModelExists($target);
    }

    public function testExistingSubuserAccessPreventsDeletion(): void
    {
        [$target, $inviter] = $this->invite();
        $server = $this->createServerModel();
        \Pterodactyl\Models\Subuser::query()->create(['server_id' => $server->id, 'user_id' => $target->id, 'permissions' => ['database.read']]);
        try {
            $this->app->make(InvitedAccountDeletionService::class)->handle($target->id, $inviter->id);
            $this->fail('An account with access was deleted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertModelExists($target);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedAccounts')]
    public function testSelfAndAdminAccountsCannotBeDeleted(bool $admin): void
    {
        [$target, $inviter] = $this->invite();
        $target->forceFill(['root_admin' => $admin])->save();
        try {
            $this->app->make(InvitedAccountDeletionService::class)->handle($target->id, $admin ? $inviter->id : $target->id);
            $this->fail('A protected account was deleted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertModelExists($target);
        }
    }

    public static function protectedAccounts(): array
    {
        return [[true], [false]];
    }
}
