<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! $this->indexExists('m_r_f_s', 'mrfs_po_number_idx')) {
                $table->index('po_number', 'mrfs_po_number_idx');
            }
            if (! $this->indexExists('m_r_f_s', 'mrfs_requester_name_idx')) {
                $table->index('requester_name', 'mrfs_requester_name_idx');
            }
        });

        Schema::table('r_f_q_s', function (Blueprint $table) {
            if (! $this->indexExists('r_f_q_s', 'rfqs_formatted_id_idx')) {
                $table->index('formatted_id', 'rfqs_formatted_id_idx');
            }
            if (! $this->indexExists('r_f_q_s', 'rfqs_rfq_id_idx')) {
                $table->index('rfq_id', 'rfqs_rfq_id_idx');
            }
        });

        Schema::table('s_r_f_s', function (Blueprint $table) {
            if (! $this->indexExists('s_r_f_s', 'srfs_formatted_id_idx')) {
                $table->index('formatted_id', 'srfs_formatted_id_idx');
            }
            if (! $this->indexExists('s_r_f_s', 'srfs_srf_id_idx')) {
                $table->index('srf_id', 'srfs_srf_id_idx');
            }
            if (! $this->indexExists('s_r_f_s', 'srfs_requester_name_idx')) {
                $table->index('requester_name', 'srfs_requester_name_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if ($this->indexExists('m_r_f_s', 'mrfs_po_number_idx')) {
                $table->dropIndex('mrfs_po_number_idx');
            }
            if ($this->indexExists('m_r_f_s', 'mrfs_requester_name_idx')) {
                $table->dropIndex('mrfs_requester_name_idx');
            }
        });

        Schema::table('r_f_q_s', function (Blueprint $table) {
            if ($this->indexExists('r_f_q_s', 'rfqs_formatted_id_idx')) {
                $table->dropIndex('rfqs_formatted_id_idx');
            }
            if ($this->indexExists('r_f_q_s', 'rfqs_rfq_id_idx')) {
                $table->dropIndex('rfqs_rfq_id_idx');
            }
        });

        Schema::table('s_r_f_s', function (Blueprint $table) {
            if ($this->indexExists('s_r_f_s', 'srfs_formatted_id_idx')) {
                $table->dropIndex('srfs_formatted_id_idx');
            }
            if ($this->indexExists('s_r_f_s', 'srfs_srf_id_idx')) {
                $table->dropIndex('srfs_srf_id_idx');
            }
            if ($this->indexExists('s_r_f_s', 'srfs_requester_name_idx')) {
                $table->dropIndex('srfs_requester_name_idx');
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
