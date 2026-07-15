<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $title = 'GO-PRINTS & GENERAL CONTRACTORS';
        $mrfReference = 'MRF-EMERALD-PRC-SERV-2026-081';
        $correctPoNumber = 'PO-150726-GO-PRINTS-0003';

        $connection = Schema::getConnection();
        $query = $connection->table('m_r_f_s')
            ->where('title', $title)
            ->where('mrf_reference', $mrfReference);

        $matchingCount = $query->count();

        if ($matchingCount === 0) {
            Log::info('fix_go_prints_po_number: no matching MRF found', [
                'title' => $title,
                'mrf_reference' => $mrfReference,
            ]);
            return;
        }

        if ($matchingCount > 1) {
            throw new RuntimeException(sprintf(
                'fix_go_prints_po_number aborted: expected exactly 1 record, found %d matching title + mrf_reference',
                $matchingCount
            ));
        }

        $record = $query->first(['id', 'po_number']);
        if (! $record) {
            throw new RuntimeException('fix_go_prints_po_number aborted: matched record could not be loaded');
        }

        if ($record->po_number === $correctPoNumber) {
            Log::info('fix_go_prints_po_number: record already has correct po_number', [
                'mrf_id' => $record->id,
                'po_number' => $record->po_number,
            ]);
            return;
        }

        DB::transaction(function () use ($connection, $record, $correctPoNumber, $title, $mrfReference) {
            Log::info('fix_go_prints_po_number: updating PO number', [
                'mrf_id' => $record->id,
                'old_po_number' => $record->po_number,
                'new_po_number' => $correctPoNumber,
                'title' => $title,
                'mrf_reference' => $mrfReference,
            ]);

            $connection->table('m_r_f_s')
                ->where('id', $record->id)
                ->update(['po_number' => $correctPoNumber]);

            Log::info('fix_go_prints_po_number: updated PO number successfully', [
                'mrf_id' => $record->id,
                'old_po_number' => $record->po_number,
                'new_po_number' => $correctPoNumber,
            ]);
        });
    }

    public function down(): void
    {
        // This migration is a one-time data fix and is intentionally irreversible.
    }
};
