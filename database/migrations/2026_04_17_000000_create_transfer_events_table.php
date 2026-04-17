<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('station_id')->index();
            $table->decimal('amount', 15, 4)->default(0);
            $table->string('status');
            $table->timestampTz('event_created_at');
            $table->timestampTz('ingested_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_events');
    }
};
