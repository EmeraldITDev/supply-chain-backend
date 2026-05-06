<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DepartmentCodesSeeder::class,
            CategoryCodesSeeder::class,
        ]);

        // Keep this seeder safe for production deployments where dev packages
        // (including faker/factories) may not be installed.
        if (app()->environment('local')) {
            User::updateOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                    'department' => 'Information Technology',
                ]
            );
        }
    }
}
