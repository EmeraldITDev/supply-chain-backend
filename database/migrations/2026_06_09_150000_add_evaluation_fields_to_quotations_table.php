<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'evaluation_notes')) {
                $table->text('evaluation_notes')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('quotations', 'evaluation_score')) {
                $table->decimal('evaluation_score', 4, 1)->nullable()->after('evaluation_notes');
            }
            if (! Schema::hasColumn('quotations', 'evaluation_updated_at')) {
                $table->timestamp('evaluation_updated_at')->nullable()->after('evaluation_score');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            foreach (['evaluation_updated_at', 'evaluation_score', 'evaluation_notes'] as $col) {
                if (Schema::hasColumn('quotations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
