<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesSchema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_vendor_creation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('realm_id', 64);
            $table->string('currency', 3);
            $table->string('status', 24)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'realm_id', 'currency'], 'qb_vendor_request_vendor_realm_currency_unique');
            $table->index(['realm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_vendor_creation_requests');
    }
};
