<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('po_number_sequences')) {
            Schema::create('po_number_sequences', function (Blueprint $table) {
                $table->id();
                // Scope key = "{DDMMYY}|{SupplierToken}" — one serial counter per supplier per day.
                $table->string('scope_key')->unique();
                $table->unsignedInteger('last_serial')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('po_number_sequences');
    }
};
