<?php

return [
    'max_batch_size' => (int) env('MAX_BATCH_SIZE', 500),
    'insert_chunk_size' => (int) env('INSERT_CHUNK_SIZE', 100),
];
