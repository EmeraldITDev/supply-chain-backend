<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->json('passenger_names'); // array of staff/passenger names
            $table->string('destination_state');
            $table->string('destination_city');
            $table->integer('number_of_nights');
            $table->string('hotel_name');
            $table->date('check_in_date');
            $table->date('check_out_date')->nullable(); // Derived from check_in + number_of_nights
            $table->unsignedBigInteger('created_by')->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('trip_id')->references('id')->on('logistics_trips')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_bookings');
    }
};
