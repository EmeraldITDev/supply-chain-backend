<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('m_r_f_s', 'po_forwarded_to_vendor_at')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->timestamp('po_forwarded_to_vendor_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('m_r_f_s', 'po_forwarded_to_vendor_at')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropColumn('po_forwarded_to_vendor_at');
        });
    }
};
