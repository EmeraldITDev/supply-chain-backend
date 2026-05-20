<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mrf_items') && !Schema::hasColumn('mrf_items', 'budget_amount')) {
            Schema::table('mrf_items', function (Blueprint $table) {
                $table->decimal('budget_amount', 15, 2)->nullable()->after('total_price');
                $table->decimal('quoted_total', 15, 2)->nullable()->after('budget_amount');
            });
        }

        if (!Schema::hasTable('srf_items')) {
            Schema::create('srf_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('srf_id')->constrained('s_r_f_s')->cascadeOnDelete();
                $table->string('item_name');
                $table->text('description')->nullable();
                $table->integer('quantity')->default(1);
                $table->string('unit', 50)->default('unit');
                $table->decimal('budget_amount', 15, 2)->nullable();
                $table->decimal('quoted_total', 15, 2)->nullable();
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->decimal('total_price', 15, 2)->nullable();
                $table->text('specifications')->nullable();
                $table->timestamps();
                $table->index('srf_id');
            });
        }

        if (Schema::hasTable('logistics_trips')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                if (!Schema::hasColumn('logistics_trips', 'workflow_stage')) {
                    $after = Schema::hasColumn('logistics_trips', 'approval_status') ? 'approval_status' : 'status';
                    $table->string('workflow_stage', 50)->default('trip_request')->after($after);
                }
                if (!Schema::hasColumn('logistics_trips', 'passenger_user_ids')) {
                    $table->json('passenger_user_ids')->nullable()->after('metadata');
                }
                if (!Schema::hasColumn('logistics_trips', 'driver_user_id')) {
                    $table->unsignedBigInteger('driver_user_id')->nullable()->after('passenger_user_ids');
                    $table->foreign('driver_user_id')->references('id')->on('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('logistics_trips', 'po_number')) {
                    $table->string('po_number', 100)->nullable();
                    $table->string('unsigned_po_url', 500)->nullable();
                    $table->string('signed_po_url', 500)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('srf_items');

        if (Schema::hasTable('mrf_items') && Schema::hasColumn('mrf_items', 'budget_amount')) {
            Schema::table('mrf_items', function (Blueprint $table) {
                $table->dropColumn(['budget_amount', 'quoted_total']);
            });
        }

        if (Schema::hasTable('logistics_trips')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                $cols = ['workflow_stage', 'passenger_user_ids', 'driver_user_id', 'po_number', 'unsigned_po_url', 'signed_po_url'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('logistics_trips', $col)) {
                        if ($col === 'driver_user_id') {
                            $table->dropForeign(['driver_user_id']);
                        }
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
