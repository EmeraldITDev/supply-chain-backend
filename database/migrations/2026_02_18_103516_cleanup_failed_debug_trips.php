<?php

use App\Models\Trip; // <- make sure this path matches your Trip model
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
        Trip::whereIn('trip_number', $tripNumbers)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
