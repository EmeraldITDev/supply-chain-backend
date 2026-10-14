<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Services\QuickBooksDiscoveryService;
use App\Services\QuickBooksTokenService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class QuickBooksPreflight extends Command
{
    protected $signature = 'quickbooks:preflight';

    protected $description = 'Check QuickBooks configuration without printing secrets or calling the accounting API';

    public function handle(QuickBooksTokenService $tokens, QuickBooksDiscoveryService $discovery): int
    {
        $failed = false;

        foreach ([
            'QUICKBOOKS_CLIENT_ID',
            'QUICKBOOKS_CLIENT_SECRET',
            'QUICKBOOKS_REDIRECT_URI',
            'QUICKBOOKS_SCOPES',
            'QUICKBOOKS_ENCRYPTION_KEY',
            'FRONTEND_URL',
        ] as $name) {
            $failed = ! $this->present($name) || $failed;
        }

        $environment = env('QUICKBOOKS_ENVIRONMENT');
        $environmentOk = is_string($environment) && in_array($environment, ['production', 'sandbox'], true);
        $this->line(($environmentOk ? '[PASS] ' : '[FAIL] ').'QUICKBOOKS_ENVIRONMENT');
        $failed = ! $environmentOk || $failed;

        $this->line('[PASS] QUICKBOOKS_DRY_RUN effective='.(config('services.quickbooks.dry_run') ? 'true' : 'false'));
        $this->line('[PASS] QUICKBOOKS_WRITES_ENABLED effective='.(config('services.quickbooks.writes_enabled') ? 'true' : 'false'));

        if (! filled(env('QUICKBOOKS_DEFAULT_ITEM_ID')) && ! filled(env('QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID'))) {
            $this->line('[WARN] QUICKBOOKS_DEFAULT_ITEM_ID and QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID are unset');
        }

        $keyOk = $tokens->encryptionKeyIsValid();
        $this->line(($keyOk ? '[PASS] ' : '[FAIL] ').'QUICKBOOKS_ENCRYPTION_KEY valid');
        $failed = ! $keyOk || $failed;

        $settingsOk = Schema::hasTable('system_settings')
            && Schema::hasColumn('system_settings', 'category')
            && Schema::hasColumn('system_settings', 'key')
            && Schema::hasColumn('system_settings', 'value');
        $this->line(($settingsOk ? '[PASS] ' : '[FAIL] ').'system_settings table and columns');
        $failed = ! $settingsOk || $failed;

        $mrfOk = Schema::hasTable('m_r_f_s')
            && Schema::hasColumn('m_r_f_s', 'quickbooks_po_id')
            && Schema::hasColumn('m_r_f_s', 'quickbooks_synced_at')
            && Schema::hasColumn('m_r_f_s', 'quickbooks_sync_status');
        $this->line(($mrfOk ? '[PASS] ' : '[FAIL] ').'m_r_f_s QuickBooks columns');
        $failed = ! $mrfOk || $failed;

        $vendorOk = Schema::hasTable('vendors')
            && Schema::hasColumn('vendors', 'quickbooks_vendor_id')
            && $this->vendorIndexExists();
        $this->line(($vendorOk ? '[PASS] ' : '[FAIL] ').'vendors.quickbooks_vendor_id indexed');
        $failed = ! $vendorOk || $failed;

        $mrf = new MRF;
        $relationsOk = $mrf->selectedVendor() instanceof BelongsTo && $mrf->items() instanceof HasMany;
        $this->line(($relationsOk ? '[PASS] ' : '[FAIL] ').'MRF selectedVendor and items relationships');
        $failed = ! $relationsOk || $failed;

        $reachable = $discovery->reachable();
        $this->line(($reachable ? '[PASS] ' : '[FAIL] ').'discovery document reachable');
        if (! $reachable && $discovery->lastHttpStatus !== null) {
            $this->line('       http_status='.$discovery->lastHttpStatus);
        }
        $failed = ! $reachable || $failed;

        $connected = false;
        if ($settingsOk && $keyOk) {
            $connected = $tokens->getConnectionStatus()['connected'] === true;
        }
        $this->line('[INFO] QuickBooks connected: '.($connected ? 'yes' : 'no'));

        if ($failed) {
            $this->error('QuickBooks preflight failed.');

            return self::FAILURE;
        }

        $this->info('QuickBooks preflight passed.');

        return self::SUCCESS;
    }

    private function present(string $name): bool
    {
        $value = env($name);
        $ok = is_string($value) && trim($value) !== '';
        $this->line(($ok ? '[PASS] ' : '[FAIL] ').$name);

        return $ok;
    }

    private function vendorIndexExists(): bool
    {
        if (! Schema::hasTable('vendors')) {
            return false;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            return $connection->selectOne(
                'SELECT 1 FROM pg_indexes WHERE indexname = ?',
                ['vendors_quickbooks_vendor_id_idx'],
            ) !== null;
        }

        if ($driver === 'sqlite') {
            return $connection->selectOne(
                'SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?',
                ['index', 'vendors_quickbooks_vendor_id_idx'],
            ) !== null;
        }

        return true;
    }
}
