<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_station_summary_and_keeps_stations_isolated(): void
    {
        $this->postJson('/api/v0/transfers', [
            'events' => [
                $this->transferEventPayload('evt-1', 'S1', 100.50, 'approved', '2026-04-17T10:00:00Z'),
                $this->transferEventPayload('evt-2', 'S1', 25.25, 'rejected', '2026-04-17T10:05:00Z'),
                $this->transferEventPayload('evt-3', 'S2', 200.00, 'approved', '2026-04-17T10:10:00Z'),
            ],
        ])->assertOk();

        $this->getJson('/api/v0/stations/S1/summary')
            ->assertOk()
            ->assertJson([
                'station_id' => 'S1',
                'total_approved_amount' => 100.5,
                'approved_events_count' => 1,
                'events_count' => 2,
            ]);

        $this->getJson('/api/v0/stations/S2/summary')
            ->assertOk()
            ->assertJson([
                'station_id' => 'S2',
                'total_approved_amount' => 200.0,
                'approved_events_count' => 1,
                'events_count' => 1,
            ]);
    }

    public function test_it_returns_404_for_unknown_station(): void
    {
        $this->getJson('/api/v0/stations/UNKNOWN/summary')
            ->assertNotFound()
            ->assertJson([
                'message' => 'Station [UNKNOWN] was not found.',
            ]);
    }
}
