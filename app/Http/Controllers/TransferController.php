<?php

namespace App\Http\Controllers;

use App\Exceptions\BatchSizeExceededException;
use App\Http\Resources\TransferIngestionResource;
use App\Services\StationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TransferController extends Controller
{
    public function ingest(Request $request, StationService $service): TransferIngestionResource|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'events' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            $result = $service->ingest($request->input('events', []));
        } catch (BatchSizeExceededException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'events' => [$exception->getMessage()],
                ],
            ], 400);
        }

        return new TransferIngestionResource($result);
    }
}
