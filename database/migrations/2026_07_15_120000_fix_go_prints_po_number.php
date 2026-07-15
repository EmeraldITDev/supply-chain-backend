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
    $correctPoNumber = 'PO-150726-GO-PRINTS-0003';
    $wrongPoNumber = 'PO-150726-COLGSOLUTIONSLIMITED-0003';

    $matchingCount = DB::table('purchase_orders')
        ->where('po_number', $wrongPoNumber)
        ->where('title', $title)
        ->count();

    if ($matchingCount === 0) {
        Log::info('fix_go_prints_po_number: no matching PO found');
        return;
    }

    if ($matchingCount > 1) {
        throw new RuntimeException(
            "fix_go_prints_po_number aborted: found {$matchingCount} matching records"
        );
    }

    DB::transaction(function () use ($wrongPoNumber, $correctPoNumber, $title) {
        $record = DB::table('purchase_orders')
            ->where('po_number', $wrongPoNumber)
            ->where('title', $title)
            ->first(['id', 'po_number']);

        Log::info('fix_go_prints_po_number: updating', [
            'id' => $record->id,
            'old' => $record->po_number,
            'new' => $correctPoNumber,
        ]);

        DB::table('purchase_orders')
            ->where('id', $record->id)
            ->update(['po_number' => $correctPoNumber]);

        Log::info('fix_go_prints_po_number: done successfully', [
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
