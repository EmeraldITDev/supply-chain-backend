<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$searches = [
    ['field' => 'scm_transaction_id', 'value' => 'f5a59cf6-a1ff-4c39-bb50-99442375da1e'],
    ['field' => 'mrf_id', 'value' => 'MRF-EMPLOYEE-2026-001'],
    ['field' => 'formatted_id', 'value' => 'MRF-EMPLOYEE-BD-CON-2026-083'],
    ['field' => 'legacy_id', 'value' => 'MRF-EMPLOYEE-2026-001'],
];

foreach ($searches as $s) {
    echo "SEARCH {$s['field']}={$s['value']}\n";
    $mrf = App\Models\MRF::where($s['field'], $s['value'])->first();
    if (! $mrf) {
        echo "  NOT FOUND\n\n";
        continue;
    }
    echo "  ID={$mrf->id}\n";
    echo "  mrf_id={$mrf->mrf_id}\n";
    echo "  formatted_id={$mrf->formatted_id}\n";
    echo "  workflow_state={$mrf->workflow_state}\n";
    echo "  current_stage={$mrf->current_stage}\n";
    echo "  status={$mrf->status}\n";
    echo "  first_approval_by_role=" . ($mrf->first_approval_by_role ?? 'NULL') . "\n";
    echo "  executive_approved=" . ($mrf->executive_approved ?? 'NULL') . "\n";
    echo "  scd_approved_by=" . ($mrf->scd_approved_by ?? 'NULL') . "\n";
    echo "  updated_at=" . ($mrf->updated_at?->toDateTimeString() ?? 'NULL') . "\n";
    echo "\n";
}

// partial formatted_id search
$partial = '%BD-CON-2026-083%';
echo "SEARCH formatted_id LIKE {$partial}\n";
$rows = App\Models\MRF::where('formatted_id', 'like', $partial)->get();
if ($rows->isEmpty()) {
    echo "  NO ROWS\n\n";
} else {
    foreach ($rows as $r) {
        echo "  ID={$r->id} mrf_id={$r->mrf_id} formatted_id={$r->formatted_id} workflow_state={$r->workflow_state} current_stage={$r->current_stage} first_approval_by_role=" . ($r->first_approval_by_role ?? 'NULL') . "\n";
    }
    echo "\n";
}

// search approval history by approver or mrf_id-like
echo "SEARCH mrf_approval_history entries for approver_id=171 or mrf_id LIKE '%2026-001%'\n";
$hist = DB::table('mrf_approval_history')
    ->where(function($q){ $q->where('approver_id', 171)->orWhere('mrf_id', 'like', '%2026-001%'); })
    ->orderByDesc('created_at')
    ->get();

if ($hist->isEmpty()) {
    echo "  NO HISTORY ROWS\n";
} else {
    foreach ($hist as $h) {
        echo "  history id={$h->id} mrf_id={$h->mrf_id} stage={$h->stage} action={$h->action} approver_id={$h->approver_id} approver_name={$h->approver_name} created_at={$h->created_at}\n";
    }
}
