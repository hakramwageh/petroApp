<?php

namespace App\Exceptions;

use RuntimeException;

class StationNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $stationId)
    {
        parent::__construct("Station [{$stationId}] was not found.");
    }
}
