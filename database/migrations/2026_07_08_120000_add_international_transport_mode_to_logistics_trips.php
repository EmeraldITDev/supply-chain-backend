<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->string('international_transport_mode', 16)
                ->nullable()
                ->after('booking_scope')
                ->comment('flight | road — only applicable when booking_scope is international');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropColumn('international_transport_mode');
        });
    }
};
