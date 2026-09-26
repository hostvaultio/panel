<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Database;

use Pterodactyl\Models\DatabaseHost;
use Illuminate\Database\Events\QueryExecuted;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Repositories\Eloquent\DatabaseRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class DatabaseCreationConcurrencyTest extends ClientApiIntegrationTestCase
{
    public function testConcurrentFirstDatabaseRequestsReturnOneSuccessAndOneQuotaRejection(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl for real MySQL contention.');
        }

        config()->set('pterodactyl.client_features.databases.enabled', true);
        [$user, $server] = $this->generateTestAccount();
        $server->update(['database_limit' => 1]);
        DatabaseHost::factory()->create(['node_id' => $server->node_id]);

        // Keep real Panel rows and transactions, replacing only external database DDL.
        $repository = $this->mock(DatabaseRepository::class);
        foreach (['createDatabase', 'createUser', 'assignUserToDatabase', 'flush'] as $method) {
            $repository->allows($method)->andReturnTrue();
        }
        $this->mock(DynamicDatabaseConnection::class)->allows('set')->andReturnNull();

        $directory = sys_get_temp_dir() . '/database-contention-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->app->make('db')->purge();
        $children = [];
        for ($index = 0; $index < 2; ++$index) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $this->app->make('db')->purge();
                    // The old controller takes this gap lock before its server lock.
                    // Let both old requests reach it to reproduce the deadlock reliably.
                    // With parent-first locking, only the winner reaches it initially.
                    $paused = false;
                    $this->app->make('db')->listen(function (QueryExecuted $query) use (&$paused, $directory, $index) {
                        if (!$paused && str_contains($query->sql, 'count(*)') && str_contains($query->sql, '`databases`') && str_contains($query->sql, 'for update')) {
                            $paused = true;
                            file_put_contents($directory . '/' . $index . '.locked', 'locked');
                            $deadline = microtime(true) + 1;
                            while (count(glob($directory . '/*.locked')) < 2 && microtime(true) < $deadline) {
                                usleep(10000);
                            }
                        }
                    });
                    file_put_contents($directory . '/' . $index . '.ready', 'ready');
                    $deadline = microtime(true) + 10;
                    while (count(glob($directory . '/*.ready')) < 2) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Child startup timed out.');
                        }
                        usleep(10000);
                    }
                    $response = $this->actingAs($user)->postJson($this->link($server, '/databases'), [
                        'database' => 'contention' . $index, 'remote' => '%',
                    ]);
                    $result = (string) $response->getStatusCode();
                } catch (\Throwable $error) {
                    $result = get_class($error);
                }
                file_put_contents($directory . '/' . $index . '.result', $result);
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $results = array_map('file_get_contents', glob($directory . '/*.result'));
        sort($results);
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        $this->app->make('db')->purge();
        $this->assertSame(['200', '400'], $results);
        $this->assertSame(1, $server->databases()->count());
    }
}
