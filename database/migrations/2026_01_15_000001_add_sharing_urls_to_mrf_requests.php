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
        Schema::table('m_r_f_s', function (Blueprint $table) {
            // Add sharing URL columns if they don't exist
            if (!Schema::hasColumn('m_r_f_s', 'unsigned_po_share_url')) {
                $table->text('unsigned_po_share_url')->nullable()->after('unsigned_po_url');
            }
            if (!Schema::hasColumn('m_r_f_s', 'signed_po_share_url')) {
                $table->text('signed_po_share_url')->nullable()->after('signed_po_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropColumn(['unsigned_po_share_url', 'signed_po_share_url']);
        });
    }
};
