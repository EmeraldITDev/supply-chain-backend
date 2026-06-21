<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'profile_completed')) {
                $table->boolean('profile_completed')->default(true)->after('notes');
            }
            if (! Schema::hasColumn('vendors', 'onboarding_source')) {
                $table->string('onboarding_source', 32)->nullable()->after('profile_completed');
            }
            if (! Schema::hasColumn('vendors', 'onboarding_email_sent_at')) {
                $table->timestamp('onboarding_email_sent_at')->nullable()->after('onboarding_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $columns = ['profile_completed', 'onboarding_source', 'onboarding_email_sent_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('vendors', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
