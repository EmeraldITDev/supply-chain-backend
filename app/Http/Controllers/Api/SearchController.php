<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\RFQ;
use App\Models\SRF;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([
                'success' => true,
                'query' => $q,
                'results' => [],
            ]);
        }

        $needle = '%' . strtolower($q) . '%';

        $mrfs = MRF::query()
            ->whereRaw('LOWER(COALESCE(formatted_id, \'\')) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(mrf_id) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(title) LIKE ?', [$needle])
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'type' => 'MRF',
                'id' => $m->mrf_id,
                'formatted_id' => $m->formatted_id,
                'legacy_id' => $m->mrf_id,
                'title' => $m->title,
                'status' => $m->status,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        $srfs = SRF::query()
            ->whereRaw('LOWER(COALESCE(formatted_id, \'\')) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(srf_id) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(title) LIKE ?', [$needle])
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'type' => 'SRF',
                'id' => $s->srf_id,
                'formatted_id' => $s->formatted_id,
                'legacy_id' => $s->srf_id,
                'title' => $s->title,
                'status' => $s->status,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        $rfqs = RFQ::query()
            ->whereRaw('LOWER(COALESCE(formatted_id, \'\')) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(rfq_id) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(title) LIKE ?', [$needle])
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'type' => 'RFQ',
                'id' => $r->rfq_id,
                'formatted_id' => $r->formatted_id,
                'legacy_id' => $r->rfq_id,
                'title' => $r->title,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'query' => $q,
            'results' => $mrfs
                ->concat($srfs)
                ->concat($rfqs)
                ->values(),
        ]);
    }
}

