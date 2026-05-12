<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (!Schema::hasColumn('m_r_f_s', 'po_draft_saved_at')) {
                $table->timestamp('po_draft_saved_at')
                    ->nullable()
                    ->after('po_signed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'po_draft_saved_at')) {
                $table->dropColumn('po_draft_saved_at');
            }
        });
    }
};
