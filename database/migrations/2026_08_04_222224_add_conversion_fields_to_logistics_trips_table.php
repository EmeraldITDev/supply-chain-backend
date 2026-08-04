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
            if (! Schema::hasColumn('logistics_trips', 'quotation_required')) {
                $table->boolean('quotation_required')->default(false)->after('selected_vendor_id');
            }

            if (! Schema::hasColumn('logistics_trips', 'logistics_request_id')) {
                $table->foreignId('logistics_request_id')->nullable()->constrained('logistics_trips')->nullOnDelete()->after('quotation_required');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            if (Schema::hasColumn('logistics_trips', 'logistics_request_id')) {
                $table->dropForeign(['logistics_request_id']);
                $table->dropColumn('logistics_request_id');
            }

            if (Schema::hasColumn('logistics_trips', 'quotation_required')) {
                $table->dropColumn('quotation_required');
            }
        });
    }
};
