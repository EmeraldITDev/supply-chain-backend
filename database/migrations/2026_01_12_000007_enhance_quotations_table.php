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
            $table->string('quote_number')->nullable()->after('vendor_id');
            $table->decimal('total_amount', 15, 2)->after('quote_number');
            $table->string('currency', 3)->default('NGN')->after('total_amount');
            
            // Add terms
            $table->integer('delivery_days')->nullable()->after('currency');
            $table->date('delivery_date')->nullable()->after('delivery_days');
            $table->string('payment_terms')->nullable()->after('delivery_date');
            $table->integer('validity_days')->default(30)->after('payment_terms');
            $table->string('warranty_period', 100)->nullable()->after('validity_days');
            
            // Add attachments
            $table->json('attachments')->nullable()->after('warranty_period');
            
            // Add notes field if not exists
            if (!Schema::hasColumn('quotations', 'notes')) {
                $table->text('notes')->nullable()->after('attachments');
            }
            
            // Add submission tracking
            $table->timestamp('submitted_at')->nullable()->after('notes');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->after('reviewed_at');
            
            // Update status if it's not already ENUM
            // (We'll keep the existing status field but document the expected values)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            
            $table->dropColumn([
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
            ]);
        });
    }
};
