<?php

namespace App\Http\Controllers;

use App\Exceptions\StationNotFoundException;
use App\Http\Resources\StationSummaryResource;
use App\Services\StationService;
use Illuminate\Http\JsonResponse;

class StationController extends Controller
{
    public function summary(string $station_id, StationService $service): StationSummaryResource|JsonResponse
    {
        try {
            return new StationSummaryResource($service->getSummary($station_id));
        } catch (StationNotFoundException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }
    }
}
