<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'source')) {
                $table->string('source', 32)->default('standard')->after('scm_transaction_id');
            }

            if (! Schema::hasColumn('m_r_f_s', 'is_po_linked')) {
                $table->boolean('is_po_linked')->default(false)->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'is_po_linked')) {
                $table->dropColumn('is_po_linked');
            }

            if (Schema::hasColumn('m_r_f_s', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
