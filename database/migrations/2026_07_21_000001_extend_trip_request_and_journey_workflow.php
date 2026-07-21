<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_trips', 'accommodation_required')) {
                $table->boolean('accommodation_required')->default(false)->after('notes');
            }
            if (! Schema::hasColumn('logistics_trips', 'accommodation_name')) {
                $table->string('accommodation_name')->nullable()->after('accommodation_required');
            }
            if (! Schema::hasColumn('logistics_trips', 'accommodation_address')) {
                $table->string('accommodation_address')->nullable()->after('accommodation_name');
            }
            if (! Schema::hasColumn('logistics_trips', 'accommodation_contact')) {
                $table->string('accommodation_contact')->nullable()->after('accommodation_address');
            }
            if (! Schema::hasColumn('logistics_trips', 'accommodation_details')) {
                $table->text('accommodation_details')->nullable()->after('accommodation_contact');
            }
            if (! Schema::hasColumn('logistics_trips', 'accommodation_estimated_cost')) {
                $table->decimal('accommodation_estimated_cost', 12, 2)->nullable()->after('accommodation_details');
            }
            if (! Schema::hasColumn('logistics_trips', 'escort_required')) {
                $table->boolean('escort_required')->default(false)->after('accommodation_estimated_cost');
            }
            if (! Schema::hasColumn('logistics_trips', 'escort_description')) {
                $table->text('escort_description')->nullable()->after('escort_required');
            }
            if (! Schema::hasColumn('logistics_trips', 'estimated_cost')) {
                $table->decimal('estimated_cost', 12, 2)->nullable()->after('escort_description');
            }
            if (! Schema::hasColumn('logistics_trips', 'comments')) {
                $table->text('comments')->nullable()->after('estimated_cost');
            }
        });

        if (! Schema::hasTable('trip_request_edits')) {
            Schema::create('trip_request_edits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('trip_request_id')->index();
                $table->unsignedBigInteger('edited_by')->nullable()->index();
                $table->string('field_name');
                $table->text('original_value')->nullable();
                $table->text('new_value')->nullable();
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->foreign('trip_request_id')->references('id')->on('logistics_trips')->cascadeOnDelete();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::table('logistics_journeys', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_journeys', 'trip_request_id')) {
                $table->unsignedBigInteger('trip_request_id')->nullable()->after('trip_id')->index();
            }
            if (! Schema::hasColumn('logistics_journeys', 'driver_name')) {
                $table->string('driver_name')->nullable()->after('trip_request_id');
            }
            if (! Schema::hasColumn('logistics_journeys', 'driver_phone')) {
                $table->string('driver_phone')->nullable()->after('driver_name');
            }
            if (! Schema::hasColumn('logistics_journeys', 'driver_email')) {
                $table->string('driver_email')->nullable()->after('driver_phone');
            }
            if (! Schema::hasColumn('logistics_journeys', 'vehicle_id')) {
                $table->unsignedBigInteger('vehicle_id')->nullable()->after('driver_email')->index();
            }
            if (! Schema::hasColumn('logistics_journeys', 'vehicle_plate_number')) {
                $table->string('vehicle_plate_number')->nullable()->after('vehicle_id');
            }
            if (! Schema::hasColumn('logistics_journeys', 'vehicle_make')) {
                $table->string('vehicle_make')->nullable()->after('vehicle_plate_number');
            }
            if (! Schema::hasColumn('logistics_journeys', 'vehicle_model')) {
                $table->string('vehicle_model')->nullable()->after('vehicle_make');
            }
            if (! Schema::hasColumn('logistics_journeys', 'departure_time')) {
                $table->timestamp('departure_time')->nullable()->after('vehicle_model');
            }
            if (! Schema::hasColumn('logistics_journeys', 'expected_arrival_time')) {
                $table->timestamp('expected_arrival_time')->nullable()->after('departure_time');
            }
            if (! Schema::hasColumn('logistics_journeys', 'actual_departure_time')) {
                $table->timestamp('actual_departure_time')->nullable()->after('expected_arrival_time');
            }
            if (! Schema::hasColumn('logistics_journeys', 'actual_arrival_time')) {
                $table->timestamp('actual_arrival_time')->nullable()->after('actual_departure_time');
            }
            if (! Schema::hasColumn('logistics_journeys', 'accommodation_name')) {
                $table->string('accommodation_name')->nullable()->after('actual_arrival_time');
            }
            if (! Schema::hasColumn('logistics_journeys', 'accommodation_address')) {
                $table->string('accommodation_address')->nullable()->after('accommodation_name');
            }
            if (! Schema::hasColumn('logistics_journeys', 'accommodation_contact')) {
                $table->string('accommodation_contact')->nullable()->after('accommodation_address');
            }
            if (! Schema::hasColumn('logistics_journeys', 'accommodation_details')) {
                $table->text('accommodation_details')->nullable()->after('accommodation_contact');
            }
            if (! Schema::hasColumn('logistics_journeys', 'accommodation_estimated_cost')) {
                $table->decimal('accommodation_estimated_cost', 12, 2)->nullable()->after('accommodation_details');
            }
            if (! Schema::hasColumn('logistics_journeys', 'escort_description')) {
                $table->text('escort_description')->nullable()->after('accommodation_estimated_cost');
            }
            if (! Schema::hasColumn('logistics_journeys', 'passengers')) {
                $table->json('passengers')->nullable()->after('escort_description');
            }
            if (! Schema::hasColumn('logistics_journeys', 'purpose')) {
                $table->string('purpose')->nullable()->after('passengers');
            }
            if (! Schema::hasColumn('logistics_journeys', 'departure_location')) {
                $table->string('departure_location')->nullable()->after('purpose');
            }
            if (! Schema::hasColumn('logistics_journeys', 'destination')) {
                $table->string('destination')->nullable()->after('departure_location');
            }
            if (! Schema::hasColumn('logistics_journeys', 'feedback')) {
                $table->text('feedback')->nullable()->after('destination');
            }
            if (! Schema::hasColumn('logistics_journeys', 'jcc_generated')) {
                $table->boolean('jcc_generated')->default(false)->after('feedback');
            }
            if (! Schema::hasColumn('logistics_journeys', 'jcc_document_id')) {
                $table->unsignedBigInteger('jcc_document_id')->nullable()->after('jcc_generated')->index();
            }
        });

        if (Schema::hasTable('logistics_journeys')) {
            Schema::table('logistics_journeys', function (Blueprint $table) {
                if (! Schema::hasColumn('logistics_journeys', 'trip_request_id')) {
                    return;
                }
                $table->foreign('trip_request_id')->references('id')->on('logistics_trips')->nullOnDelete();
                $table->foreign('vehicle_id')->references('id')->on('logistics_vehicles')->nullOnDelete();
                $table->foreign('jcc_document_id')->references('id')->on('logistics_documents')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_request_edits');

        Schema::table('logistics_journeys', function (Blueprint $table) {
            $table->dropForeign(['trip_request_id']);
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['jcc_document_id']);
            $table->dropColumn([
                'trip_request_id',
                'driver_name',
                'driver_phone',
                'driver_email',
                'vehicle_id',
                'vehicle_plate_number',
                'vehicle_make',
                'vehicle_model',
                'departure_time',
                'expected_arrival_time',
                'actual_departure_time',
                'actual_arrival_time',
                'accommodation_name',
                'accommodation_address',
                'accommodation_contact',
                'accommodation_details',
                'accommodation_estimated_cost',
                'escort_description',
                'passengers',
                'purpose',
                'departure_location',
                'destination',
                'feedback',
                'jcc_generated',
                'jcc_document_id',
            ]);
        });

        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropColumn([
                'accommodation_required',
                'accommodation_name',
                'accommodation_address',
                'accommodation_contact',
                'accommodation_details',
                'accommodation_estimated_cost',
                'escort_required',
                'escort_description',
                'estimated_cost',
                'comments',
            ]);
        });
    }
};
