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
        Schema::table('users', function (Blueprint $table) {
            // Check if columns already exist before adding
            if (!Schema::hasColumn('users', 'vendor_id')) {
                $table->foreignId('vendor_id')->nullable()->after('employee_id')->constrained('vendors')->onDelete('set null'); // Link user to vendor
            }
            // must_change_password and password_changed_at already exist, skip them
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn(['vendor_id', 'must_change_password', 'password_changed_at']);
        });
    }
};
