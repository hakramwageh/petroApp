<?php

namespace App\Repositories;

use App\Contracts\TransferStoreInterface;
use Illuminate\Support\Facades\DB;

class PostgresTransferRepository implements TransferStoreInterface
{
    public function insertBatch(array $events): array
    {
        if ($events === []) {
            return ['inserted' => 0, 'dbDuplicates' => 0];
        }

        $inserted = 0;
        $dbDuplicates = 0;

        foreach (array_chunk($events, (int) config('transfers.insert_chunk_size', 100)) as $chunk) {
            $result = $this->insertChunk($chunk);
            $inserted += $result['inserted'];
            $dbDuplicates += $result['dbDuplicates'];
        }

        return [
            'inserted' => $inserted,
            'dbDuplicates' => $dbDuplicates,
        ];
    }

    public function getStationSummary(string $stationId): ?array
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS events_count,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved_events_count,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0)
                        AS total_approved_amount
             FROM transfer_events
             WHERE station_id = ?",
            [$stationId]
        );

        if ($row === null || (int) $row->events_count === 0) {
            return null;
        }

        return [
            'station_id' => $stationId,
            'total_approved_amount' => (float) $row->total_approved_amount,
            'approved_events_count' => (int) $row->approved_events_count,
            'events_count' => (int) $row->events_count,
        ];
    }

    /**
     * @param  array<int, array{
     *     event_id: string,
     *     station_id: string,
     *     amount: float,
     *     status: string,
     *     created_at: string
     * }>  $chunk
     * @return array{inserted: int, dbDuplicates: int}
     */
    private function insertChunk(array $chunk): array
    {
        $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
        $bindings = [];

        foreach ($chunk as $event) {
            array_push(
                $bindings,
                $event['event_id'],
                $event['station_id'],
                $event['amount'],
                $event['status'],
                $event['created_at'],
            );
        }

        $rows = DB::select(
            "INSERT INTO transfer_events (event_id, station_id, amount, status, event_created_at)
             VALUES {$placeholders}
             ON CONFLICT (event_id) DO NOTHING
             RETURNING event_id",
            $bindings
        );

        $inserted = count($rows);

        return [
            'inserted' => $inserted,
            'dbDuplicates' => count($chunk) - $inserted,
        ];
    }
}
