<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            // Add new columns for multi-vendor support
            $table->boolean('multi_vendor')->default(false)->after('vendor_id');
            $table->unsignedBigInteger('selected_vendor_id')->nullable()->after('multi_vendor')->index();
            $table->string('approval_status', 50)->default('draft')->after('selected_vendor_id'); // DRAFT | PENDING_REVIEW | APPROVED | REJECTED

            // Add foreign key for selected_vendor_id
            $table->foreign('selected_vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropForeign(['selected_vendor_id']);
            $table->dropColumn(['multi_vendor', 'selected_vendor_id', 'approval_status']);
        });
    }
};
