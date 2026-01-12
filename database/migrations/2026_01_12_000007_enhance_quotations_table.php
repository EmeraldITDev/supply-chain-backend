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
        Schema::table('quotations', function (Blueprint $table) {
            // Add quote details
            if (!Schema::hasColumn('quotations', 'quote_number')) {
                $table->string('quote_number')->nullable()->after('vendor_id');
            }
            
            if (!Schema::hasColumn('quotations', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->after('quote_number');
            }
            
            if (!Schema::hasColumn('quotations', 'currency')) {
                $table->string('currency', 3)->default('NGN')->after('total_amount');
            }
            
            // Add terms
            if (!Schema::hasColumn('quotations', 'delivery_days')) {
                $table->integer('delivery_days')->nullable()->after('currency');
            }
            
            if (!Schema::hasColumn('quotations', 'delivery_date')) {
                $table->date('delivery_date')->nullable()->after('delivery_days');
            }
            
            if (!Schema::hasColumn('quotations', 'payment_terms')) {
                $table->string('payment_terms')->nullable()->after('delivery_date');
            }
            
            if (!Schema::hasColumn('quotations', 'validity_days')) {
                $table->integer('validity_days')->default(30)->after('payment_terms');
            }
            
            if (!Schema::hasColumn('quotations', 'warranty_period')) {
                $table->string('warranty_period', 100)->nullable()->after('validity_days');
            }
            
            // Add attachments
            if (!Schema::hasColumn('quotations', 'attachments')) {
                $table->json('attachments')->nullable()->after('warranty_period');
            }
            
            // Add notes field if not exists
            if (!Schema::hasColumn('quotations', 'notes')) {
                $table->text('notes')->nullable()->after('attachments');
            }
            
            // Add submission tracking
            if (!Schema::hasColumn('quotations', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('notes');
            }
            
            if (!Schema::hasColumn('quotations', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }
            
            if (!Schema::hasColumn('quotations', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->after('reviewed_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Drop foreign key if exists
            if (Schema::hasColumn('quotations', 'reviewed_by')) {
                $table->dropForeign(['reviewed_by']);
            }
            
            // Drop columns only if they exist
            $columnsToCheck = [
                'quote_number',
                'total_amount',
                'currency',
                'delivery_days',
                'delivery_date',
                'payment_terms',
                'validity_days',
                'warranty_period',
                'attachments',
                'submitted_at',
                'reviewed_at',
                'reviewed_by',
            ];
            
            $columnsToDrop = [];
            foreach ($columnsToCheck as $column) {
                if (Schema::hasColumn('quotations', $column)) {
                    $columnsToDrop[] = $column;
                }
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
