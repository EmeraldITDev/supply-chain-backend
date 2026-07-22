<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            $table->string('ownership')->nullable()->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            $table->dropColumn('ownership');
        });
    }
};
