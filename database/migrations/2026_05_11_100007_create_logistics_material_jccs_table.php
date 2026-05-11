<?php

use App\Enums\MaterialJCCStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('logistics_material_jccs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Reference to material movement
            $table->uuid('material_movement_id')->unique()->index();
            $table->foreign('material_movement_id')
                ->references('id')
                ->on('logistics_material_movements')
                ->cascadeOnDelete();
            
            // Reference number (auto-generated: JCC/MAT/[YYYYMM]-[seq])
            $table->string('reference_number')->unique()->index();
            
            // Vendor details (denormalized for quick access)
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('vendor_name')->nullable();
            $table->foreign('vendor_id')
                ->references('id')
                ->on('vendors')
                ->nullOnDelete();
            
            // Purchase order reference (if applicable)
            $table->string('po_number')->nullable()->index();
            
            // Certification details
            $table->text('certification_text');
            $table->enum('condition_on_arrival', ['good', 'damaged', 'partial'])->default('good');
            
            // Status tracking
            $table->enum('status', MaterialJCCStatus::values())->default(MaterialJCCStatus::DRAFT->value)->index();
            
            // Audit fields
            $table->unsignedBigInteger('issued_by')->index();
            $table->dateTime('issued_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            
            // Foreign keys
            $table->foreign('issued_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            
            // Timestamps
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_material_jccs');
    }
};
