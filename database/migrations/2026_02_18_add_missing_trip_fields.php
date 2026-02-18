<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            // Add trip_type field (personnel, material, mixed)
            $table->string('trip_type', 50)->default('personnel')->after('trip_code');

            // Add priority field (low, normal, high, urgent)
            $table->string('priority', 50)->default('normal')->after('trip_type');

            // Add purpose field (separate from description/title)
            $table->text('purpose')->nullable()->after('description');

            // Add cancellation tracking
            $table->timestamp('cancelled_at')->nullable()->after('actual_arrival_at');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');

            // Add foreign key for cancelled_by
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropForeignIdFor('cancelled_by');
            $table->dropColumn(['trip_type', 'priority', 'purpose', 'cancelled_at', 'cancelled_by']);
        });
    }
};
