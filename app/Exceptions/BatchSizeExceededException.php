<?php

namespace App\Exceptions;

use RuntimeException;

class BatchSizeExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $actualSize,
        public readonly int $maxSize,
    ) {
        parent::__construct("Batch size {$actualSize} exceeds the maximum allowed size of {$maxSize}.");
    }
}
