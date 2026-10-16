<?php

use App\Services\SignedPurchaseOrderRepairService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(SignedPurchaseOrderRepairService::class)->repair();
    }

    public function down(): void
    {
        // Signature evidence is preserved. The previous pending label was incorrect.
    }
};
