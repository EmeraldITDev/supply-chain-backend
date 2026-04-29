<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->text('attachment_url')->nullable()->change();
            $table->text('attachment_share_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->string('attachment_url')->nullable()->change();
            $table->string('attachment_share_url')->nullable()->change();
        });
    }
};
