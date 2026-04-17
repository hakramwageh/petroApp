<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationSummaryResource extends JsonResource
{
    /**
     * @return array<string, string|int|float>
     */
    public function toArray(Request $request): array
    {
        return [
            'station_id' => $this['station_id'],
            'total_approved_amount' => $this['total_approved_amount'],
            'approved_events_count' => $this['approved_events_count'],
            'events_count' => $this['events_count'],
        ];
    }
}
