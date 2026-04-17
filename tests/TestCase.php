<?php

namespace Tests;

use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use Symfony\Component\Process\Process;

abstract class TestCase extends BaseTestCase
{
    protected function transferEventPayload(
        string $eventId,
        string $stationId,
        float $amount,
        string $status,
        string $createdAt,
    ): array {
        return [
            'event_id' => $eventId,
            'station_id' => $stationId,
            'amount' => $amount,
            'status' => $status,
            'created_at' => $createdAt,
        ];
    }

    protected function runAgainstServedApplication(callable $callback): void
    {
        $port = random_int(8100, 8999);
        $databaseConfig = $this->testDatabaseConfig();

        $process = new Process(
            ['php', 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}"],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_KEY' => env('APP_KEY'),
                'APP_URL' => "http://127.0.0.1:{$port}",
                'DB_CONNECTION' => config('database.default', 'pgsql'),
                'TEST_DB_HOST' => $databaseConfig['host'],
                'TEST_DB_PORT' => $databaseConfig['port'],
                'TEST_DB_DATABASE' => $databaseConfig['database'],
                'TEST_DB_USERNAME' => $databaseConfig['username'],
                'TEST_DB_PASSWORD' => $databaseConfig['password'],
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAX_BATCH_SIZE' => (string) env('MAX_BATCH_SIZE', '500'),
                'INSERT_CHUNK_SIZE' => (string) env('INSERT_CHUNK_SIZE', '100'),
            ]
        );

        $process->start();

        $client = new Client([
            'base_uri' => "http://127.0.0.1:{$port}",
            'http_errors' => false,
            'timeout' => 10,
        ]);

        try {
            $this->waitForApplicationServer($client);
            $callback($client);
        } finally {
            $process->stop(3);
        }
    }

    protected function truncateTransferEventsOutsideTransaction(): void
    {
        $databaseConfig = $this->testDatabaseConfig();

        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $databaseConfig['host'],
                $databaseConfig['port'],
                $databaseConfig['database'],
            ),
            $databaseConfig['username'],
            $databaseConfig['password'],
        );

        $pdo->exec('TRUNCATE TABLE transfer_events RESTART IDENTITY');
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function testDatabaseConfig(): array
    {
        /** @var array{host: string, port: string, database: string, username: string, password: string} $config */
        $config = [
            'host' => (string) config('database.connections.pgsql.host', '127.0.0.1'),
            'port' => (string) config('database.connections.pgsql.port', '5432'),
            'database' => (string) config('database.connections.pgsql.database', 'petroapp_test'),
            'username' => (string) config('database.connections.pgsql.username', 'postgres'),
            'password' => (string) config('database.connections.pgsql.password', 'postgres'),
        ];

        return $config;
    }

    private function waitForApplicationServer(Client $client): void
    {
        $attempts = 0;

        while ($attempts < 20) {
            try {
                $response = $client->get('/up');

                if ($response->getStatusCode() === 200) {
                    return;
                }
            } catch (\Throwable) {
                // Keep retrying until the test server is ready.
            }

            usleep(250_000);
            $attempts++;
        }

        self::fail('Timed out waiting for the Laravel test server to start.');
    }
}
