<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Populate make_model from type if make_model is empty
        DB::statement("
            UPDATE logistics_vehicles 
            SET make_model = type 
            WHERE make_model IS NULL AND type IS NOT NULL
        ");
        
        // Populate fields from metadata JSON if they exist there
        DB::statement("
            UPDATE logistics_vehicles 
            SET 
                year = CASE 
                    WHEN year IS NULL AND metadata->>'year' IS NOT NULL 
                    THEN (metadata->>'year')::integer 
                    ELSE year 
                END,
                fuel_type = CASE 
                    WHEN fuel_type IS NULL AND metadata->>'fuel_type' IS NOT NULL 
                    THEN metadata->>'fuel_type' 
                    ELSE fuel_type 
                END,
                make_model = CASE 
                    WHEN make_model IS NULL AND metadata->>'model' IS NOT NULL 
                    THEN metadata->>'model' 
                    ELSE make_model 
                END
            WHERE metadata IS NOT NULL
        ");
    }

    public function down(): void
    {
        // No rollback needed for data migration
    }
};
