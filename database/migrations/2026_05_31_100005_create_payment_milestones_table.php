<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_schedule_id')->constrained('payment_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('milestone_number');
            $table->string('label');
            $table->decimal('percentage', 5, 2);
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('trigger_condition', 50);
            $table->json('required_documents')->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->string('finance_ap_reference')->nullable();
            $table->foreignId('predecessor_milestone_id')->nullable()->constrained('payment_milestones')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payment_schedule_id', 'milestone_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_milestones');
    }
};
