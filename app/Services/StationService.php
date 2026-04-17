<?php

namespace App\Services;

use App\Contracts\TransferStoreInterface;
use App\Exceptions\StationNotFoundException;

class StationService
{
    public function __construct(private readonly TransferStoreInterface $store)
    {
    }

    /**
     * @return array{
     *     station_id: string,
     *     total_approved_amount: float,
     *     approved_events_count: int,
     *     events_count: int
     * }
     */
    public function getSummary(string $stationId): array
    {
        return $this->store->getStationSummary($stationId)
            ?? throw new StationNotFoundException($stationId);
    }
}
