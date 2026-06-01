<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->string('booking_scope', 32)
                ->nullable()
                ->after('trip_type')
                ->comment('within_state | outside_state — staff trip request lead-time rules');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropColumn('booking_scope');
        });
    }
};
