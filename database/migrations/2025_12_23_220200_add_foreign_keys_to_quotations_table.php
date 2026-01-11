<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds foreign key constraints that were removed from initial table creation
     * to avoid dependency issues when tables are created in alphabetical order.
     */
    public function up(): void
    {
        // Check and add foreign keys only if they don't exist
        $this->addForeignKeyIfNotExists('quotations', 'quotations_rfq_id_foreign', function (Blueprint $table) {
            $table->foreign('rfq_id')->references('id')->on('r_f_q_s')->onDelete('cascade');
        });

        $this->addForeignKeyIfNotExists('quotations', 'quotations_vendor_id_foreign', function (Blueprint $table) {
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });

        $this->addForeignKeyIfNotExists('vendor_registrations', 'vendor_registrations_vendor_id_foreign', function (Blueprint $table) {
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
        });

        $this->addForeignKeyIfNotExists('rfq_vendors', 'rfq_vendors_rfq_id_foreign', function (Blueprint $table) {
            $table->foreign('rfq_id')->references('id')->on('r_f_q_s')->onDelete('cascade');
        });

        $this->addForeignKeyIfNotExists('rfq_vendors', 'rfq_vendors_vendor_id_foreign', function (Blueprint $table) {
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });
    }

    /**
     * Helper method to add foreign key only if it doesn't exist
     */
    private function addForeignKeyIfNotExists(string $table, string $constraintName, callable $callback): void
    {
        // Check if constraint exists
        $exists = \DB::select(
            "SELECT constraint_name FROM information_schema.table_constraints 
             WHERE table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'",
            [$table, $constraintName]
        );

        if (empty($exists)) {
            Schema::table($table, $callback);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['rfq_id']);
            $table->dropForeign(['vendor_id']);
        });

        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
        });

        Schema::table('rfq_vendors', function (Blueprint $table) {
            $table->dropForeign(['rfq_id']);
            $table->dropForeign(['vendor_id']);
        });
    }
};

