<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing columns to existing audit_logs table
        Schema::table('audit_logs', function (Blueprint $table) {
            // Check and add each column if it doesn't exist
            if (!Schema::hasColumn('audit_logs', 'action')) {
                $table->string('action', 100)->index()->after('id');
            }
            
            if (!Schema::hasColumn('audit_logs', 'actor_id')) {
                $table->unsignedBigInteger('actor_id')->nullable()->index()->after('action');
            }
            
            if (!Schema::hasColumn('audit_logs', 'actor_type')) {
                $table->string('actor_type', 100)->nullable()->after('actor_id');
            }
            
            if (!Schema::hasColumn('audit_logs', 'entity_type')) {
                $table->string('entity_type', 100)->nullable()->index()->after('actor_type');
            }
            
            if (!Schema::hasColumn('audit_logs', 'entity_id')) {
                $table->string('entity_id', 100)->nullable()->index()->after('entity_type');
            }
            
            if (!Schema::hasColumn('audit_logs', 'payload')) {
                $table->json('payload')->nullable()->after('entity_id');
            }
            
            if (!Schema::hasColumn('audit_logs', 'ip_address')) {
                $table->ipAddress('ip_address')->nullable()->after('payload');
            }
            
            if (!Schema::hasColumn('audit_logs', 'user_agent')) {
                $table->string('user_agent', 500)->nullable()->after('ip_address');
            }
        });
    }

    public function down(): void
    {
        // Do not drop columns as they may be used by HRIS
        // Schema::table('audit_logs', function (Blueprint $table) {
        //     $table->dropColumn([
        //         'action', 'actor_id', 'actor_type', 'entity_type',
        //         'entity_id', 'payload', 'ip_address', 'user_agent'
        //     ]);
        // });
    }
};
