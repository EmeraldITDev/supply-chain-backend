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
        $table->index('department', 'idx_users_department');
        $table->index('designated_requisition_creator', 'idx_users_designated');
        $table->index(['department', 'designated_requisition_creator'], 'idx_users_dept_designated');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex('idx_users_department');
        $table->dropIndex('idx_users_designated');
        $table->dropIndex('idx_users_dept_designated');
    });
}
};
