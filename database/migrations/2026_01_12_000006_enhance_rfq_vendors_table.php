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
        Schema::table('rfq_vendors', function (Blueprint $table) {
            // Add engagement tracking fields
            $table->timestamp('sent_at')->nullable()->after('vendor_id');
            $table->timestamp('viewed_at')->nullable()->after('sent_at');
            $table->boolean('responded')->default(false)->after('viewed_at');
            $table->timestamp('responded_at')->nullable()->after('responded');

            // Add indexes for performance
            $table->index('responded');
            $table->index(['rfq_id', 'responded']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_vendors', function (Blueprint $table) {
            $table->dropIndex(['responded']);
            $table->dropIndex(['rfq_id', 'responded']);
            
            $table->dropColumn([
                'sent_at',
                'viewed_at',
                'responded',
                'responded_at',
            ]);
        });
    }
};
