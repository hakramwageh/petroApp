<?php

namespace App\Contracts;

interface TransferStoreInterface
{
    /**
     * Persist pre-validated, intra-batch-deduplicated events.
     * Cross-request idempotency is enforced by the database unique constraint.
     *
     * @param  array<int, array{
     *     event_id: string,
     *     station_id: string,
     *     amount: float,
     *     status: string,
     *     created_at: string
     * }>  $events
     * @return array{inserted: int, dbDuplicates: int}
     */
    public function insertBatch(array $events): array;

    /**
     * @return array{
     *     station_id: string,
     *     total_approved_amount: float,
     *     approved_events_count: int,
     *     events_count: int
     * }|null
     */
    public function getStationSummary(string $stationId): ?array;
}
