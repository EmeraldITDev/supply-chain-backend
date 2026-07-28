<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach ([
    ['field' => 'mrf_id', 'value' => 'MRF-EMPLOYEE-2026-001'],
    ['field' => 'formatted_id', 'value' => 'MRF-EMPLOYEE-BD-CON-2026-083'],
    ['field' => 'legacy_id', 'value' => 'MRF-EMPLOYEE-2026-001'],
] as $search) {
    echo "SEARCH {$search['field']}={$search['value']}\n";
    $mrf = App\Models\MRF::where($search['field'], $search['value'])->first();
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
