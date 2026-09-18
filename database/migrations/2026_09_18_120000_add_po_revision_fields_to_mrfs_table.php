<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'unlocked_by')) {
                $table->unsignedBigInteger('unlocked_by')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'unlocked_at')) {
                $table->timestamp('unlocked_at')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'unlock_reason')) {
                $table->text('unlock_reason')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'revision_number')) {
                $table->unsignedInteger('revision_number')->default(0);
            }
            if (! Schema::hasColumn('m_r_f_s', 'revision_history')) {
                $table->json('revision_history')->nullable();
            }
            if (! Schema::hasColumn('m_r_f_s', 'revision_snapshot')) {
                $table->json('revision_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            foreach ([
                'unlocked_by',
                'unlocked_at',
                'unlock_reason',
                'revision_number',
                'revision_history',
                'revision_snapshot',
            ] as $column) {
                if (Schema::hasColumn('m_r_f_s', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
