<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       // We use DB::table instead of the Trip model to avoid "Class not found"
       DB::table('trips')->whereIn('trip_code', [
        'TRIP-20260217-YZ7RGJ', 
        'TRIP-20260216-YJ2FSU', 
        'TRIP-20260218-7KZIR2'
        ])->delete();

        DB::table('trips')
            ->where('status', 'cancelled')
            ->whereNull('scheduled_departure')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
