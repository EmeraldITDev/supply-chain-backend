<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Add review_status if it doesn't exist
            if (!Schema::hasColumn('quotations', 'review_status')) {
                $table->string('review_status', 50)->default('pending')->after('status');
            }
            
            // Add revision_notes if it doesn't exist
            if (!Schema::hasColumn('quotations', 'revision_notes')) {
                $table->text('revision_notes')->nullable()->after('rejection_reason');
            }
            
            // Ensure rejection_reason exists (should already exist)
            if (!Schema::hasColumn('quotations', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('review_status');
            }
            
            // Ensure reviewed_by and reviewed_at exist
            if (!Schema::hasColumn('quotations', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('revision_notes')
                    ->constrained('users')->onDelete('set null');
            }
            
            if (!Schema::hasColumn('quotations', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }
        });
        
        // Add check constraint for review_status
        DB::statement("
            ALTER TABLE quotations
            DROP CONSTRAINT IF EXISTS quotations_review_status_check;
        ");
        
        DB::statement("
            ALTER TABLE quotations
            ADD CONSTRAINT quotations_review_status_check 
            CHECK (review_status IN (
                'pending',
                'under_review',
                'approved',
                'rejected',
                'revision_requested'
            ));
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            DB::statement("ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_review_status_check;");
            
            if (Schema::hasColumn('quotations', 'reviewed_at')) {
                $table->dropColumn('reviewed_at');
            }
            if (Schema::hasColumn('quotations', 'reviewed_by')) {
                $table->dropForeign(['reviewed_by']);
                $table->dropColumn('reviewed_by');
            }
            if (Schema::hasColumn('quotations', 'revision_notes')) {
                $table->dropColumn('revision_notes');
            }
            if (Schema::hasColumn('quotations', 'review_status')) {
                $table->dropColumn('review_status');
            }
        });
    }
};
