<?php

namespace App\Services;

use App\Contracts\TransferStoreInterface;
use App\Exceptions\BatchSizeExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

class TransferIngestionService
{
    public function __construct(private readonly TransferStoreInterface $store)
    {
    }

    /**
     * @param  array<int, mixed>  $rawEvents
     * @return array{
     *     inserted: int,
     *     duplicates: int,
     *     invalid: int,
     *     validation_errors?: array<int, array{index: int, errors: array<string, array<int, string>>}>
     * }
     */
    public function ingest(array $rawEvents): array
    {
        Log::info('transfer.ingest.start', [
            'batch_size' => count($rawEvents),
        ]);

        $start = hrtime(true);
        $maxBatchSize = (int) config('transfers.max_batch_size', 500);

        if (count($rawEvents) > $maxBatchSize) {
            throw new BatchSizeExceededException(count($rawEvents), $maxBatchSize);
        }

        ['valid' => $validEvents, 'invalid' => $invalidCount, 'errors' => $errors] = $this->validateEvents($rawEvents);
        ['unique' => $uniqueEvents, 'inBatchDuplicates' => $inBatchDuplicates] = $this->deduplicateWithinBatch($validEvents);
        ['inserted' => $inserted, 'dbDuplicates' => $dbDuplicates] = $this->store->insertBatch($uniqueEvents);

        $duplicates = $inBatchDuplicates + $dbDuplicates;
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        Log::info('transfer.ingest.done', [
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'invalid' => $invalidCount,
            'duration_ms' => $durationMs,
        ]);

        if ($invalidCount > 0) {
            Log::warning('transfer.ingest.invalid_events', [
                'invalid_count' => $invalidCount,
                'first_error' => $errors[0] ?? null,
            ]);
        }

        return array_filter([
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'invalid' => $invalidCount,
            'validation_errors' => $errors === [] ? null : $errors,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<int, mixed>  $rawEvents
     * @return array{
     *     valid: array<int, array{
     *         event_id: string,
     *         station_id: string,
     *         amount: float,
     *         status: string,
     *         created_at: string
     *     }>,
     *     invalid: int,
     *     errors: array<int, array{index: int, errors: array<string, array<int, string>>}>
     * }
     */
    private function validateEvents(array $rawEvents): array
    {
        $validEvents = [];
        $errors = [];

        foreach ($rawEvents as $index => $event) {
            $validator = $this->makeEventValidator($event);

            if ($validator->fails()) {
                $errors[] = [
                    'index' => $index,
                    'errors' => $validator->errors()->toArray(),
                ];

                continue;
            }

            /** @var array<string, mixed> $validated */
            $validated = $validator->validated();

            $validEvents[] = [
                'event_id' => (string) $validated['event_id'],
                'station_id' => (string) $validated['station_id'],
                'amount' => (float) $validated['amount'],
                'status' => (string) $validated['status'],
                'created_at' => CarbonImmutable::parse((string) $validated['created_at'])->toIso8601String(),
            ];
        }

        return [
            'valid' => $validEvents,
            'invalid' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  mixed  $event
     */
    private function makeEventValidator(mixed $event): LaravelValidator
    {
        return Validator::make(is_array($event) ? $event : [], [
            'event_id' => ['required', 'string', 'max:255'],
            'station_id' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'max:255'],
            'created_at' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $value)) {
                        $fail("The {$attribute} field must be a valid ISO8601 timestamp.");

                        return;
                    }

                    try {
                        CarbonImmutable::parse($value);
                    } catch (\Throwable) {
                        $fail("The {$attribute} field must be a valid ISO8601 timestamp.");
                    }
                },
            ],
        ]);
    }

    /**
     * @param  array<int, array{
     *     event_id: string,
     *     station_id: string,
     *     amount: float,
     *     status: string,
     *     created_at: string
     * }>  $events
     * @return array{
     *     unique: array<int, array{
     *         event_id: string,
     *         station_id: string,
     *         amount: float,
     *         status: string,
     *         created_at: string
     *     }>,
     *     inBatchDuplicates: int
     * }
     */
    private function deduplicateWithinBatch(array $events): array
    {
        $seenEventIds = [];
        $uniqueEvents = [];
        $inBatchDuplicates = 0;

        foreach ($events as $event) {
            if (isset($seenEventIds[$event['event_id']])) {
                $inBatchDuplicates++;

                continue;
            }

            $seenEventIds[$event['event_id']] = true;
            $uniqueEvents[] = $event;
        }

        return [
            'unique' => $uniqueEvents,
            'inBatchDuplicates' => $inBatchDuplicates,
        ];
    }
}
