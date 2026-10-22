<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The scm_ prefix only existed to avoid clashing with HRIS while both apps shared
 * one database. SCM now has its own database, so the tables use plain names.
 */
return new class extends Migration
{
    private const RENAMES = [
        'scm_generated_reports' => 'generated_reports',
        'scm_scheduled_reports' => 'scheduled_reports',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
