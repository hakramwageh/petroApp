<?php

namespace Tests\Feature;

use GuzzleHttp\Pool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_inserts_a_batch_and_counts_in_batch_duplicates(): void
    {
        $response = $this->postJson('/api/v0/transfers', [
            'events' => [
                $this->transferEventPayload('evt-1', 'S1', 100.00, 'approved', '2026-04-17T10:00:00Z'),
                $this->transferEventPayload('evt-2', 'S1', 75.00, 'approved', '2026-04-17T10:01:00Z'),
                $this->transferEventPayload('evt-1', 'S1', 100.00, 'approved', '2026-04-17T10:00:00Z'),
            ],
        ]);

        $response->assertOk()->assertJson([
            'inserted' => 2,
            'duplicates' => 1,
            'invalid' => 0,
        ]);
    }

    public function test_duplicate_event_is_idempotent_across_requests(): void
    {
        $payload = [
            'events' => [
                $this->transferEventPayload('evt-100', 'S1', 55.75, 'approved', '2026-04-17T10:00:00Z'),
            ],
        ];

        $this->postJson('/api/v0/transfers', $payload)
            ->assertOk()
            ->assertJson([
                'inserted' => 1,
                'duplicates' => 0,
                'invalid' => 0,
            ]);

        $this->postJson('/api/v0/transfers', $payload)
            ->assertOk()
            ->assertJson([
                'inserted' => 0,
                'duplicates' => 1,
                'invalid' => 0,
            ]);

        $this->getJson('/api/v0/stations/S1/summary')
            ->assertOk()
            ->assertJson([
                'station_id' => 'S1',
                'total_approved_amount' => 55.75,
                'approved_events_count' => 1,
                'events_count' => 1,
            ]);
    }

    public function test_out_of_order_arrival_produces_the_same_totals(): void
    {
        $this->postJson('/api/v0/transfers', [
            'events' => [
                $this->transferEventPayload('evt-new', 'S1', 90.00, 'approved', '2026-04-17T12:00:00Z'),
            ],
        ])->assertOk();

        $this->postJson('/api/v0/transfers', [
            'events' => [
                $this->transferEventPayload('evt-old', 'S1', 10.00, 'approved', '2026-04-17T08:00:00Z'),
            ],
        ])->assertOk();

        $this->getJson('/api/v0/stations/S1/summary')
            ->assertOk()
            ->assertJson([
                'station_id' => 'S1',
                'total_approved_amount' => 100.0,
                'approved_events_count' => 2,
                'events_count' => 2,
            ]);
    }

    public function test_concurrent_ingestion_of_the_same_event_ids_does_not_double_insert(): void
    {
        $payload = [
            'events' => [
                $this->transferEventPayload('evt-concurrent-1', 'S1', 125.00, 'approved', '2026-04-17T10:00:00Z'),
                $this->transferEventPayload('evt-concurrent-2', 'S1', 50.00, 'approved', '2026-04-17T10:01:00Z'),
            ],
        ];

        $this->runAgainstServedApplication(function ($client) use ($payload): void {
            $requests = function () use ($client, $payload) {
                for ($i = 0; $i < 10; $i++) {
                    yield static fn () => $client->postAsync('/api/v0/transfers', [
                        'json' => $payload,
                    ]);
                }
            };

            $responses = [];

            $pool = new Pool($client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => static function ($response) use (&$responses): void {
                    $responses[] = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                },
            ]);

            $pool->promise()->wait();

            $insertedTotal = array_sum(array_map(static fn (array $response): int => $response['inserted'], $responses));
            $duplicatesTotal = array_sum(array_map(static fn (array $response): int => $response['duplicates'], $responses));

            self::assertSame(2, $insertedTotal);
            self::assertSame(18, $duplicatesTotal);

            $summaryResponse = $client->get('/api/v0/stations/S1/summary');
            $summary = json_decode((string) $summaryResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(200, $summaryResponse->getStatusCode());
            self::assertSame(175.0, $summary['total_approved_amount']);
            self::assertSame(2, $summary['approved_events_count']);
            self::assertSame(2, $summary['events_count']);
        });

        $this->truncateTransferEventsOutsideTransaction();
    }

    public function test_unknown_status_is_stored_but_not_included_in_approved_total(): void
    {
        $this->postJson('/api/v0/transfers', [
            'events' => [
                $this->transferEventPayload('evt-unknown', 'S9', 88.00, 'pending_review', '2026-04-17T10:00:00Z'),
            ],
        ])->assertOk();

        $this->getJson('/api/v0/stations/S9/summary')
            ->assertOk()
            ->assertJson([
                'station_id' => 'S9',
                'total_approved_amount' => 0.0,
                'approved_events_count' => 0,
                'events_count' => 1,
            ]);
    }

    public function test_it_partially_accepts_batches_with_invalid_events(): void
    {
        $response = $this->postJson('/api/v0/transfers', [
            'events' => [
                $this->transferEventPayload('evt-valid-1', 'S1', 10.00, 'approved', '2026-04-17T10:00:00Z'),
                [
                    'event_id' => 'evt-invalid-1',
                    'station_id' => 'S1',
                    'amount' => -5,
                    'status' => 'approved',
                    'created_at' => 'not-an-iso-date',
                ],
                $this->transferEventPayload('evt-valid-2', 'S1', 20.00, 'approved', '2026-04-17T10:02:00Z'),
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'inserted' => 2,
                'duplicates' => 0,
                'invalid' => 1,
            ])
            ->assertJsonPath('validation_errors.0.index', 1)
            ->assertJsonPath('validation_errors.0.errors.amount.0', 'The amount field must be at least 0.')
            ->assertJsonPath('validation_errors.0.errors.created_at.0', 'The created_at field must be a valid ISO8601 timestamp.');
    }

    public function test_it_returns_400_when_events_is_missing_or_not_an_array(): void
    {
        $this->postJson('/api/v0/transfers', [])
            ->assertStatus(400)
            ->assertJsonPath('errors.events.0', 'The events field is required.');

        $this->postJson('/api/v0/transfers', ['events' => 'bad-shape'])
            ->assertStatus(400)
            ->assertJsonPath('errors.events.0', 'The events field must be an array.');
    }

    public function test_it_returns_400_when_batch_size_is_exceeded(): void
    {
        config()->set('transfers.max_batch_size', 1);

        $this->postJson('/api/v0/transfers', [
            'events' => [
                $this->transferEventPayload('evt-1', 'S1', 10.00, 'approved', '2026-04-17T10:00:00Z'),
                $this->transferEventPayload('evt-2', 'S1', 20.00, 'approved', '2026-04-17T10:01:00Z'),
            ],
        ])->assertStatus(400)
            ->assertJsonPath('errors.events.0', 'Batch size 2 exceeds the maximum allowed size of 1.');
    }
}
