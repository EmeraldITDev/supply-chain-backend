<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('po_terms_templates')) {
            Schema::create('po_terms_templates', function (Blueprint $table) {
                $table->id();
                $table->enum('po_type', ['goods', 'services', 'logistics', 'rfq']);
                $table->longText('content');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['po_type', 'is_active']);
            });
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (!Schema::hasColumn('m_r_f_s', 'custom_terms')) {
                $table->longText('custom_terms')->nullable()->after('po_special_terms');
            }
            if (!Schema::hasColumn('m_r_f_s', 'procurement_manager_id')) {
                $table->foreignId('procurement_manager_id')->nullable()->after('selected_vendor_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('m_r_f_s', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (Schema::hasColumn('m_r_f_s', 'procurement_manager_id')) {
                $table->dropConstrainedForeignId('procurement_manager_id');
            }
            if (Schema::hasColumn('m_r_f_s', 'custom_terms')) {
                $table->dropColumn('custom_terms');
            }
        });

        Schema::dropIfExists('po_terms_templates');
    }
};
