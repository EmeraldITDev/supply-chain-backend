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
        DB::table('logistics_trips')
            ->where('trip_code', 'TRQ-20260804-J0TIHG')
            ->whereIn('status', ['submitted', 'pending', 'approved'])
            ->update([
                'workflow_stage' => 'converted_to_logistics_request',
                'status' => 'converted',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-reversible data fix — intentionally empty
    }
};
