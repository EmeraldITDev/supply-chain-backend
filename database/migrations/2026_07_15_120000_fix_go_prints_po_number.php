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

        $identifierColumn = Schema::hasColumn('m_r_f_s', 'formatted_id') ? 'formatted_id' : 'mrf_id';
        $identifierLabel = $identifierColumn === 'formatted_id' ? 'formatted_id' : 'mrf_id';

        $query = DB::table('m_r_f_s')
            ->where('title', $title)
            ->where($identifierColumn, $mrfReference);

        $matchingCount = $query->count();

        if ($matchingCount === 0) {
            Log::info('fix_go_prints_po_number: no matching MRF found', [
                'title' => $title,
                $identifierLabel => $mrfReference,
            ]);
            return;
        }

        if ($matchingCount > 1) {
            throw new RuntimeException(sprintf(
                'fix_go_prints_po_number aborted: expected exactly 1 record, found %d matching title + %s',
                $matchingCount,
                $identifierLabel
            ));
        }

        DB::transaction(function () use ($query, $correctPoNumber, $title, $mrfReference, $identifierLabel) {
            $record = $query->first(['id', 'po_number']);
            if (! $record) {
                throw new RuntimeException('fix_go_prints_po_number aborted: matched record could not be loaded');
            }

            Log::info('fix_go_prints_po_number: updating MRF PO number', [
                'id' => $record->id,
                'old_po_number' => $record->po_number,
                'new_po_number' => $correctPoNumber,
                'title' => $title,
                $identifierLabel => $mrfReference,
            ]);

            DB::table('m_r_f_s')
                ->where('id', $record->id)
                ->update(['po_number' => $correctPoNumber]);

            Log::info('fix_go_prints_po_number: updated successfully', [
                'id' => $record->id,
                'new_po_number' => $correctPoNumber,
            ]);
        });
    }

    public function down(): void
    {
        // This migration is a one-time data fix and is intentionally irreversible.
    }
};
