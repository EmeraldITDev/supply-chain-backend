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
        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->json('documents')->nullable()->after('contact_person'); // Store document metadata as JSON
            $table->string('temp_password')->nullable()->after('documents'); // Temporary password for approved vendors
            $table->timestamp('password_changed_at')->nullable()->after('temp_password'); // Track when password was changed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_registrations', function (Blueprint $table) {
            $table->dropColumn(['documents', 'temp_password', 'password_changed_at']);
        });
    }
};
