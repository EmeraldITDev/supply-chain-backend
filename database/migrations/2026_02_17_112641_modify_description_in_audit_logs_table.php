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
        // In the shared database HRIS created audit_logs.description; SCM's own database must
        // create it, because Services/Logistics/AuditLogger always writes it.
        $exists = Schema::hasColumn('audit_logs', 'description');

        Schema::table('audit_logs', function (Blueprint $table) use ($exists) {
            $exists
                ? $table->text('description')->nullable()->change()
                : $table->text('description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            //
        });
    }
};
