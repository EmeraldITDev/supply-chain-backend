<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! $this->indexExists('m_r_f_s', 'mrfs_created_at_idx')) {
                $table->index('created_at', 'mrfs_created_at_idx');
            }
            if (! $this->indexExists('m_r_f_s', 'mrfs_formatted_id_idx')) {
                $table->index('formatted_id', 'mrfs_formatted_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if ($this->indexExists('m_r_f_s', 'mrfs_created_at_idx')) {
                $table->dropIndex('mrfs_created_at_idx');
            }
            if ($this->indexExists('m_r_f_s', 'mrfs_formatted_id_idx')) {
                $table->dropIndex('mrfs_formatted_id_idx');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $row = $connection->selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index],
            );

            return $row !== null;
        }

        if ($driver === 'mysql') {
            $row = $connection->selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $index],
            );

            return $row !== null;
        }

        return false;
    }
};
