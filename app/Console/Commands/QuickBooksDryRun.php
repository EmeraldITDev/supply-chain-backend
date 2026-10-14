<?php

namespace App\Console\Commands;

use App\Exceptions\QuickBooksException;
use App\Models\MRF;
use App\Services\QuickBooksPOSyncService;
use Illuminate\Console\Command;

class QuickBooksDryRun extends Command
{
    protected $signature = 'quickbooks:dry-run {mrfId}';

    protected $description = 'Validate a signed purchase order and print the JSON that would be sent, without writing';

    public function handle(QuickBooksPOSyncService $sync): int
    {
        $mrf = $this->findMrf((string) $this->argument('mrfId'));
        if ($mrf === null) {
            $this->error('Purchase order not found.');

            return self::FAILURE;
        }

        try {
            $result = $sync->sync($mrf, true, true);
        } catch (QuickBooksException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['results'] as $row) {
            $this->line(($row['passed'] ? '[PASS] ' : '[FAIL] ').$row['check'].': '.$row['detail']);
        }
        foreach ($result['assumptions'] as $assumption) {
            $this->line('[ASSUMPTION] '.$assumption);
        }

        $payload = $this->redact(is_array($result['payload'] ?? null) ? $result['payload'] : []);
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (($result['passed'] ?? false) !== true) {
            $this->error('Dry run failed validation.');

            return self::FAILURE;
        }

        $this->info('Dry run passed. Nothing was sent to QuickBooks and the purchase order was not updated.');

        return self::SUCCESS;
    }

    private function findMrf(string $mrfId): ?MRF
    {
        return MRF::query()->where(function ($query) use ($mrfId) {
            $query->where('mrf_id', $mrfId)->orWhere('formatted_id', $mrfId);
            if (ctype_digit($mrfId)) {
                $query->orWhere('id', (int) $mrfId);
            }
        })->first();
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/token|secret|authorization|password/i', $key)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = $this->redact($item);
        }

        return $redacted;
    }
}
