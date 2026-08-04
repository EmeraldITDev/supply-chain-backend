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
        Schema::table('logistics_journeys', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_journeys', 'scheduled_departure_at')) {
                $table->timestamp('scheduled_departure_at')->nullable()->after('destination');
            }
            if (! Schema::hasColumn('logistics_journeys', 'scheduled_arrival_at')) {
                $table->timestamp('scheduled_arrival_at')->nullable()->after('scheduled_departure_at');
            }
            if (! Schema::hasColumn('logistics_journeys', 'accommodation_hotel_name')) {
                $table->string('accommodation_hotel_name')->nullable()->after('escort_description');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_journeys', function (Blueprint $table) {
            $columns = ['scheduled_departure_at', 'scheduled_arrival_at', 'accommodation_hotel_name'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('logistics_journeys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
