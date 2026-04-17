<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'station_id',
        'amount',
        'status',
        'event_created_at',
        'ingested_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'event_created_at' => 'immutable_datetime',
        'ingested_at' => 'immutable_datetime',
    ];
}
