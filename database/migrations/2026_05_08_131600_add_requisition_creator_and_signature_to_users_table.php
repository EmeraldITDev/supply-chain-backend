<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'designated_requisition_creator')) {
                $table->boolean('designated_requisition_creator')->default(false)->after('department');
            }

            if (!Schema::hasColumn('users', 'signature_image_path')) {
                $table->string('signature_image_path')->nullable()->after('designated_requisition_creator');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'signature_image_path')) {
                $table->dropColumn('signature_image_path');
            }
            if (Schema::hasColumn('users', 'designated_requisition_creator')) {
                $table->dropColumn('designated_requisition_creator');
            }
        });
    }
};
