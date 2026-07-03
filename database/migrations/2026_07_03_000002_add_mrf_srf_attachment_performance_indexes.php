<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('m_r_f_s', ['status', 'current_stage', 'created_at'], 'mrfs_status_stage_created_idx');
        $this->addIndex('m_r_f_s', ['workflow_state', 'created_at'], 'mrfs_workflow_created_idx');
        $this->addIndex('m_r_f_s', ['requester_id', 'created_at'], 'mrfs_requester_created_idx');
        $this->addIndex('s_r_f_s', ['status', 'created_at'], 'srfs_status_created_idx');
        $this->addIndex('s_r_f_s', ['status', 'current_stage', 'created_at'], 'srfs_status_stage_created_idx');
        $this->addIndex('s_r_f_s', ['requester_id', 'created_at'], 'srfs_requester_created_idx');
        $this->addIndex('s_r_f_s', ['date', 'created_at'], 'srfs_date_created_idx');
        $this->addIndex('mrf_line_items', ['mrf_id', 'item_name'], 'mrf_line_items_mrf_name_idx');
        $this->addIndex('srf_line_items', ['srf_id', 'item_name'], 'srf_line_items_srf_name_idx');
        $this->addIndex('attachments', ['attachable_type', 'attachable_id', 'created_at'], 'attachments_attachable_created_idx');
    }

    public function down(): void
    {
        $this->dropIndex('attachments', 'attachments_attachable_created_idx');
        $this->dropIndex('srf_line_items', 'srf_line_items_srf_name_idx');
        $this->dropIndex('mrf_line_items', 'mrf_line_items_mrf_name_idx');
        $this->dropIndex('s_r_f_s', 'srfs_date_created_idx');
        $this->dropIndex('s_r_f_s', 'srfs_requester_created_idx');
        $this->dropIndex('s_r_f_s', 'srfs_status_stage_created_idx');
        $this->dropIndex('s_r_f_s', 'srfs_status_created_idx');
        $this->dropIndex('m_r_f_s', 'mrfs_requester_created_idx');
        $this->dropIndex('m_r_f_s', 'mrfs_workflow_created_idx');
        $this->dropIndex('m_r_f_s', 'mrfs_status_stage_created_idx');
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
