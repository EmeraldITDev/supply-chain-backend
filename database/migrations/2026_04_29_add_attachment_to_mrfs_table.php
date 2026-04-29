<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mrfs', function (Blueprint $table) {
            $table->string('attachment_url')->nullable()->after('pfi_share_url');
            $table->string('attachment_share_url')->nullable()->after('attachment_url');
            $table->string('attachment_name')->nullable()->after('attachment_share_url');
        });
    }

    public function down(): void
    {
        Schema::table('mrfs', function (Blueprint $table) {
            $table->dropColumn(['attachment_url', 'attachment_share_url', 'attachment_name']);
        });
    }
};
