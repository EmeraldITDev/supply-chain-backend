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
        Schema::table('r_f_q_s', function (Blueprint $table) {
            // Add category field (from MRF)
            if (!Schema::hasColumn('r_f_q_s', 'category')) {
                $table->string('category')->nullable()->after('title');
            }
            
            // Add supporting_documents as JSON to store array of document URLs/metadata
            if (!Schema::hasColumn('r_f_q_s', 'supporting_documents')) {
                $table->json('supporting_documents')->nullable()->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('r_f_q_s', function (Blueprint $table) {
            if (Schema::hasColumn('r_f_q_s', 'supporting_documents')) {
                $table->dropColumn('supporting_documents');
            }
            if (Schema::hasColumn('r_f_q_s', 'category')) {
                $table->dropColumn('category');
            }
        });
    }
};
