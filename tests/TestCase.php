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
        $process = new Process(
            ['php', 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}"],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_KEY' => env('APP_KEY'),
                'APP_URL' => "http://127.0.0.1:{$port}",
                'DB_CONNECTION' => env('DB_CONNECTION', 'pgsql'),
                'DB_HOST' => env('DB_HOST', 'db'),
                'DB_PORT' => env('DB_PORT', '5432'),
                'DB_DATABASE' => env('DB_DATABASE', 'petroapp_test'),
                'DB_USERNAME' => env('DB_USERNAME', 'postgres'),
                'DB_PASSWORD' => env('DB_PASSWORD', 'postgres'),
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
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                env('DB_HOST', 'db'),
                env('DB_PORT', '5432'),
                env('DB_DATABASE', 'petroapp_test'),
            ),
            env('DB_USERNAME', 'postgres'),
            env('DB_PASSWORD', 'postgres'),
        );

        $pdo->exec('TRUNCATE TABLE transfer_events RESTART IDENTITY');
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
