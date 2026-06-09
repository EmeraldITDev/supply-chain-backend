<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'linked_po_id')) {
                $table->string('linked_po_id', 64)->nullable()->after('is_po_linked');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'linked_po_id')) {
                $table->dropColumn('linked_po_id');
            }
        });
    }
};
