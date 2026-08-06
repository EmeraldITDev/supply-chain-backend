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
    {
    DB::statement('CREATE INDEX IF NOT EXISTS idx_users_department ON users(department)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_users_designated ON users(designated_requisition_creator)');
    DB::statement('CREATE INDEX IF NOT EXISTS idx_users_dept_designated ON users(department, designated_requisition_creator)');
    }
}

public function down(): void
{
    {
    DB::statement('DROP INDEX IF EXISTS idx_users_department');
    DB::statement('DROP INDEX IF EXISTS idx_users_designated');
    DB::statement('DROP INDEX IF EXISTS idx_users_dept_designated');
    }
}
};
