<?php

use App\Models\MRF;
use Carbon\Carbon;

if (! function_exists('mrfUsesFinanceAp')) {
    function mrfUsesFinanceAp(MRF $mrf): bool
    {
        $cutover = config('finance_ap.cutover_date');

        return $cutover && $mrf->created_at->gte(Carbon::parse($cutover));
    }
}
