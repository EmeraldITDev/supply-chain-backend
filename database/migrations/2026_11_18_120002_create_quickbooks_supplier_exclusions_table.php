<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_supplier_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('realm_id', 64);
            $table->string('quickbooks_vendor_id', 64);
            $table->string('currency', 3);
            $table->text('reason');
            $table->foreignId('excluded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('excluded_at');
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->string('status', 16)->default('excluded');
            $table->timestamps();

            $table->unique(['realm_id', 'quickbooks_vendor_id', 'currency'], 'qb_supplier_exclusion_identity_unique');
            $table->index(['realm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_supplier_exclusions');
    }
};
