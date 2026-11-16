<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables and columns SCM relies on that only HRIS's migrations ever created in the shared
 * database: the employees table (SCM reads job title / department and links users to it) and
 * users.employee_id, users.must_change_password, users.password_changed_at.
 *
 * SCM's own database gets the employee work fields only (no personal or payroll data).
 * Every step is skipped where the table/column already exists (the shared Render database).
 * Dated before 2025_12_29_223801_add_vendor_fields_to_users_table, which places a column
 * after users.employee_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id();
                $table->string('first_name');
                $table->string('middle_name')->nullable();
                $table->string('last_name');
                $table->string('email')->unique();
                $table->string('job_title')->nullable();
                $table->string('department')->nullable();
                $table->string('employment_type')->nullable();
                $table->string('work_location')->nullable();
                $table->string('employment_status')->nullable();
                $table->string('employee_code')->nullable()->unique();
                $table->string('staff_id', 20)->nullable()->unique();
                $table->boolean('profile_incomplete')->default(false);
                $table->timestamps();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'employee_id')) {
                // SCM never deletes employees; null the link rather than cascade-delete the user.
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(true);
            }
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: on the shared database these belong to HRIS's migrations.
    }
};
