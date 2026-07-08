<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex(
            'm_r_f_s',
            ['workflow_state', 'first_approval_by_role', 'created_at'],
            'mrfs_workflow_first_approval_created_idx',
        );

        $this->addIndex(
            'vendor_registrations',
            ['status', 'created_at'],
            'vendor_registrations_status_created_idx',
        );

        if (Schema::hasTable('logistics_trips')) {
            $this->addIndex(
                'logistics_trips',
                ['workflow_stage', 'status', 'created_at'],
                'logistics_trips_workflow_status_created_idx',
            );
        }
    }

    public function down(): void
    {
        $this->dropIndex('m_r_f_s', 'mrfs_workflow_first_approval_created_idx');
        $this->dropIndex('vendor_registrations', 'vendor_registrations_status_created_idx');

        if (Schema::hasTable('logistics_trips')) {
            $this->dropIndex('logistics_trips', 'logistics_trips_workflow_status_created_idx');
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || $this->indexExists($tableName, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! $this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
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

        if ($driver === 'sqlite') {
            $rows = $connection->select('PRAGMA index_list('.$connection->getTablePrefix().$table.')');

            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
        }

        return false;
    }
};
