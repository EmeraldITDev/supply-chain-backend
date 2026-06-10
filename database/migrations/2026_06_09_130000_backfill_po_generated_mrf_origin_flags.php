<?php

use App\Models\MRF;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('m_r_f_s', 'source')) {
            return;
        }

        MRF::query()
            ->where(function ($query) {
                $query->where('source', 'standard')->orWhereNull('source');
            })
            ->where(function ($query) {
                $query
                    ->whereRaw('LOWER(COALESCE(justification, \'\')) LIKE ?', ['%manual po created without rfq%'])
                    ->orWhereRaw('LOWER(COALESCE(justification, \'\')) LIKE ?', ['%vendor and pricing captured directly on the purchase order%']);
            })
            ->orderBy('id')
            ->each(function (MRF $mrf) {
                $mrf->update([
                    'source' => 'po_generated',
                    'is_po_linked' => true,
                    'linked_po_id' => filled($mrf->po_number) ? $mrf->po_number : $mrf->mrf_id,
                ]);
            });

        // Manual PO flow: PO issued without any RFQ on record (legacy rows before flags existed).
        MRF::query()
            ->where(function ($query) {
                $query->where('source', 'standard')->orWhereNull('source');
            })
            ->whereDoesntHave('rfqs')
            ->whereNotNull('po_number')
            ->where('po_number', '!=', '')
            ->orderBy('id')
            ->each(function (MRF $mrf) {
                $mrf->update([
                    'source' => 'po_generated',
                    'is_po_linked' => true,
                    'linked_po_id' => $mrf->po_number,
                ]);
            });
    }

    public function down(): void
    {
        // Non-reversible — cannot distinguish backfilled rows from organically tagged ones.
    }
};
