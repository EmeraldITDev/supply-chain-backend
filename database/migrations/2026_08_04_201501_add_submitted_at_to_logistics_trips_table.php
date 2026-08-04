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
        Schema::table('logistics_trips', function (Blueprint $table) {
            $cols = Schema::getColumnListing('logistics_trips');

            if (! in_array('submitted_at', $cols, true)) {
                $table->timestamp('submitted_at')->nullable()->after('status');
            }

            if (! in_array('logistics_recommendation', $cols, true)) {
                $table->text('logistics_recommendation')->nullable();
            }

            if (! in_array('escort_personnel_count', $cols, true)) {
                $table->unsignedSmallInteger('escort_personnel_count')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropColumnIfExists('submitted_at');
            $table->dropColumnIfExists('logistics_recommendation');
            $table->dropColumnIfExists('escort_personnel_count');
        });
    }
};
