<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesSchema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_quickbooks_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('realm_id', 64);
            $table->string('currency', 3);
            $table->string('quickbooks_vendor_id', 64);
            $table->string('status', 24)->default('pending');
            $table->string('match_method', 32);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'realm_id', 'currency'], 'vendor_qb_map_vendor_realm_currency_unique');
            $table->unique(['realm_id', 'quickbooks_vendor_id'], 'vendor_qb_map_realm_qb_vendor_unique');
            $table->index(['realm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_quickbooks_mappings');
    }
};
