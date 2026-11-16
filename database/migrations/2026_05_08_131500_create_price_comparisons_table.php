<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_comparisons')) {
            return;
        }

        Schema::create('price_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('m_r_f_s')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->text('item_description');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('quantity', 15, 2);
            $table->decimal('total_price', 15, 2);
            $table->boolean('is_selected')->default(false);
            $table->text('selection_reason')->nullable();
            $table->timestamps();

            if (Schema::getConnection()->getDriverName() !== 'mysql' && Schema::getConnection()->getDriverName() !== 'mariadb') {
                $table->index(['purchase_order_id', 'item_description']);
            }
            $table->index(['vendor_id', 'is_selected']);
        });

        // MySQL/MariaDB cannot index a whole TEXT column: index its first 191 characters.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE price_comparisons ADD INDEX price_comparisons_purchase_order_id_item_description_index '
                . '(purchase_order_id, item_description(191))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_comparisons');
    }
};
