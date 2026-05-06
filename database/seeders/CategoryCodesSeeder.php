<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoryCodesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // MRF categories
            ['category_name' => 'Laptops', 'code' => 'LAP', 'request_type' => 'MRF'],
            ['category_name' => 'Stationery', 'code' => 'STA', 'request_type' => 'MRF'],
            ['category_name' => 'Furniture', 'code' => 'FUR', 'request_type' => 'MRF'],
            ['category_name' => 'Vehicle', 'code' => 'VEH', 'request_type' => 'MRF'],
            ['category_name' => 'Equipment', 'code' => 'EQP', 'request_type' => 'MRF'],
            ['category_name' => 'Consumables', 'code' => 'CON', 'request_type' => 'MRF'],
            ['category_name' => 'Spare Parts', 'code' => 'SPR', 'request_type' => 'MRF'],
            ['category_name' => 'Safety', 'code' => 'SAF', 'request_type' => 'MRF'],
            ['category_name' => 'Other', 'code' => 'OTH', 'request_type' => 'MRF'],

            // SRF categories (mapped from service_type)
            ['category_name' => 'Repair', 'code' => 'REP', 'request_type' => 'SRF'],
            ['category_name' => 'Consultancy', 'code' => 'CSL', 'request_type' => 'SRF'],
            ['category_name' => 'Training', 'code' => 'TRN', 'request_type' => 'SRF'],
            ['category_name' => 'Maintenance', 'code' => 'MNT', 'request_type' => 'SRF'],
            ['category_name' => 'Cleaning', 'code' => 'CLN', 'request_type' => 'SRF'],
            ['category_name' => 'Security', 'code' => 'SEC', 'request_type' => 'SRF'],
            ['category_name' => 'Request', 'code' => 'REQ', 'request_type' => 'SRF'],
            ['category_name' => 'Other', 'code' => 'OTH', 'request_type' => 'SRF'],
        ];

        foreach ($rows as $row) {
            DB::table('category_codes')->updateOrInsert(
                ['category_name' => $row['category_name']],
                ['code' => $row['code'], 'request_type' => $row['request_type'], 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}

