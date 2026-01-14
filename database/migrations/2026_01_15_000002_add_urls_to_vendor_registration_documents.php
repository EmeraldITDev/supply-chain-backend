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
        Schema::table('vendor_registration_documents', function (Blueprint $table) {
            // Add URL columns if they don't exist
            if (!Schema::hasColumn('vendor_registration_documents', 'file_url')) {
                $table->text('file_url')->nullable()->after('file_path');
            }
            if (!Schema::hasColumn('vendor_registration_documents', 'file_share_url')) {
                $table->text('file_share_url')->nullable()->after('file_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_registration_documents', function (Blueprint $table) {
            $table->dropColumn(['file_url', 'file_share_url']);
        });
    }
};
