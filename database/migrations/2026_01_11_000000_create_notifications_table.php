<?php

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
        // Drop orphaned indexes if they exist (from failed previous migration)
        try {
            \DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index');
            \DB::statement('DROP INDEX IF EXISTS notifications_read_at_index');
        } catch (\Exception $e) {
            // Ignore if indexes don't exist
        }

        // Only create table if it doesn't exist
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                
                $table->index(['notifiable_type', 'notifiable_id']);
                $table->index('read_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
