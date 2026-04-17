<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferIngestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'inserted' => $this['inserted'],
            'duplicates' => $this['duplicates'],
            'invalid' => $this['invalid'],
            'validation_errors' => $this['validation_errors'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
