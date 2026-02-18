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
        //
        $tripNumbers = [
            'TRIP-20260217-YZ7RGJ',
            'TRIP-20260216-YJ2FSU',
            'TRIP-20260218-7KZIR2'
        ];
        // Delete trips with these trip numbers
        DB::table('trips')
            ->whereIn('trip_number', $tripNumbers)
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
