<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_07_21_000001 was edited to add these columns after it had already run in
 * production, so they were never created there. The Journey model uses
 * feedback_triggered.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('logistics_journeys', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_journeys', 'destination_detail')) {
                $table->string('destination_detail')->nullable()->after('departure_location');
            }
            if (! Schema::hasColumn('logistics_journeys', 'feedback_triggered')) {
                $table->boolean('feedback_triggered')->default(false)->after('feedback');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_journeys', function (Blueprint $table) {
            foreach (['destination_detail', 'feedback_triggered'] as $column) {
                if (Schema::hasColumn('logistics_journeys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
