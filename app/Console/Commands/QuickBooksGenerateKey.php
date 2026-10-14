<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

class QuickBooksGenerateKey extends Command
{
    protected $signature = 'quickbooks:generate-key';

    protected $description = 'Print a new AES-256 key for QUICKBOOKS_ENCRYPTION_KEY';

    public function handle(): int
    {
        $key = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc'));
        $this->line($key);
        $this->warn('Set this as QUICKBOOKS_ENCRYPTION_KEY before connecting QuickBooks. Do not commit it.');

        return self::SUCCESS;
    }
}
