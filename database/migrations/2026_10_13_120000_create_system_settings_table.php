<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasTable('system_settings')) {
            if ($driver === 'pgsql') {
                DB::statement(<<<'SQL'
                    CREATE TABLE IF NOT EXISTS system_settings (
                        id BIGSERIAL PRIMARY KEY,
                        category VARCHAR(64) NOT NULL,
                        "key" VARCHAR(128) NOT NULL,
                        value TEXT NULL,
                        created_at TIMESTAMP NULL,
                        updated_at TIMESTAMP NULL
                    )
                SQL);
            } else {
                Schema::create('system_settings', function (Blueprint $table) {
                    $table->id();
                    $table->string('category', 64);
                    $table->string('key', 128);
                    $table->text('value')->nullable();
                    $table->timestamps();
                });
            }
        }

        $this->addColumnIfMissing('category', 'VARCHAR(64)');
        $this->addColumnIfMissing('key', 'VARCHAR(128)');
        $this->addColumnIfMissing('value', 'TEXT');
        $this->addColumnIfMissing('created_at', 'TIMESTAMP NULL');
        $this->addColumnIfMissing('updated_at', 'TIMESTAMP NULL');

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS system_settings_category_key_unique ON system_settings (category, key)');
        } elseif (! $this->indexExists('system_settings', 'system_settings_category_key_unique')) {
            Schema::table('system_settings', function (Blueprint $table) {
                $table->unique(['category', 'key'], 'system_settings_category_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }

    private function addColumnIfMissing(string $column, string $definition): void
    {
        if (Schema::hasColumn('system_settings', $column)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $quoted = $column === 'key' ? '"key"' : $column;

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS {$quoted} {$definition}");

            return;
        }

        DB::statement("ALTER TABLE system_settings ADD COLUMN {$quoted} {$definition}");
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $row = $connection->selectOne(
                'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ? AND tbl_name = ?',
                ['index', $index, $table],
            );

            return $row !== null;
        }

        return false;
    }
};
