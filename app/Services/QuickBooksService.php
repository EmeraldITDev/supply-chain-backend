<?php

namespace App\Services;

use App\Exceptions\QuickBooksException;
use App\Models\Vendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QuickBooksService
{
    public function __construct(
        private QuickBooksTokenService $tokens,
        private QuickBooksDiscoveryService $discovery,
    ) {}

    public function writesAllowed(): bool
    {
        return config('services.quickbooks.dry_run') === false
            && config('services.quickbooks.writes_enabled') === true;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], ?int $mrfId = null, string $operation = 'read'): array
    {
        return $this->request('GET', $path, null, $query, false, $mrfId, $operation);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, array $query = [], ?int $mrfId = null, string $operation = 'create'): array
    {
        if (! $this->writesAllowed()) {
            throw new QuickBooksException(
                'QuickBooks writes are disabled. A POST is sent only when QUICKBOOKS_DRY_RUN=false and QUICKBOOKS_WRITES_ENABLED=true.',
                status: 409,
            );
        }

        return $this->request('POST', $path, $body, $query, false, $mrfId, $operation);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $statement, ?int $mrfId = null, string $operation = 'query'): array
    {
        return $this->get('/query', ['query' => $statement], $mrfId, $operation);
    }

    public function entityExists(string $entity, string $id, ?int $mrfId = null): bool
    {
        $json = $this->findById($entity, $id, $mrfId);

        return $json !== null;
    }

    public function findVendorByName(string $name, ?int $mrfId = null): ?string
    {
        $literal = self::escapeQueryLiteral($name);
        $result = $this->query(
            "select Id from Vendor where DisplayName = '{$literal}' maxresults 1",
            $mrfId,
            'find_vendor',
        );
        $rows = self::normalizeList(data_get($result, 'QueryResponse.Vendor'));
        $id = $rows[0]['Id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    public function createVendor(Vendor $vendor, ?int $mrfId = null): string
    {
        $name = trim((string) $vendor->name);
        if ($name === '') {
            throw new QuickBooksException('The selected vendor has no name to use as the QuickBooks DisplayName.');
        }

        $body = ['DisplayName' => $name];
        if (filled($vendor->email)) {
            $body['PrimaryEmailAddr'] = ['Address' => $vendor->email];
        }
        if (filled($vendor->phone)) {
            $body['PrimaryPhone'] = ['FreeFormNumber' => $vendor->phone];
        }

        $json = $this->post('/vendor', $body, [], $mrfId, 'create_vendor');
        $id = (string) data_get($json, 'Vendor.Id', '');
        if ($id === '') {
            throw new QuickBooksException('QuickBooks did not return a vendor id.', status: 502);
        }

        $vendor->quickbooks_vendor_id = $id;
        $vendor->save();

        return $id;
    }

    /**
     * @return array{Id: string, SyncToken: string, DocNumber: ?string, POStatus: string}|null
     */
    public function findPurchaseOrderByDocNumber(string $docNumber, ?int $mrfId = null): ?array
    {
        $literal = self::escapeQueryLiteral($docNumber);
        $result = $this->query(
            "select Id, SyncToken, DocNumber, POStatus from PurchaseOrder where DocNumber = '{$literal}' maxresults 1",
            $mrfId,
            'find_purchase_order',
        );
        $rows = self::normalizeList(data_get($result, 'QueryResponse.PurchaseOrder'));
        if ($rows === []) {
            return null;
        }

        return $this->trimPurchaseOrder($rows[0]);
    }

    /**
     * @return array{Id: string, SyncToken: string, DocNumber: ?string, POStatus: string}|null
     */
    public function readPurchaseOrder(string $id, ?int $mrfId = null): ?array
    {
        $json = $this->findById('purchaseorder', $id, $mrfId, 'read_purchase_order');
        if ($json === null) {
            return null;
        }

        $po = $json['PurchaseOrder'] ?? null;

        return is_array($po) ? $this->trimPurchaseOrder($po) : null;
    }

    /**
     * Read-only company settings used to validate a purchase order. The raw body is not stored.
     *
     * @return array{home_currency: ?string, multi_currency: bool, ap_accounts: list<array{Id: string, Name: string, AccountType: string}>}
     */
    public function accountingContext(?int $mrfId = null): array
    {
        $realm = $this->tokens->realmId();
        $prefs = $this->get('/preferences', [], $mrfId, 'preferences');
        $ap = $this->query(
            "select Id, Name, AccountType from Account where AccountType = 'Accounts Payable' maxresults 50",
            $mrfId,
            'ap_accounts',
        );

        $home = data_get($prefs, 'Preferences.CurrencyPrefs.HomeCurrency.value')
            ?? data_get($prefs, 'Preferences.CurrencyPrefs.HomeCurrency');
        if (is_array($home)) {
            $home = $home['value'] ?? null;
        }

        $accounts = [];
        foreach (self::normalizeList(data_get($ap, 'QueryResponse.Account')) as $account) {
            $accounts[] = [
                'Id' => (string) ($account['Id'] ?? ''),
                'Name' => (string) ($account['Name'] ?? ''),
                'AccountType' => (string) ($account['AccountType'] ?? ''),
            ];
        }

        return [
            'home_currency' => is_string($home) && $home !== '' ? strtoupper($home) : null,
            'multi_currency' => filter_var(data_get($prefs, 'Preferences.CurrencyPrefs.MultiCurrencyEnabled'), FILTER_VALIDATE_BOOLEAN),
            'ap_accounts' => $accounts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectionReport(): array
    {
        $realm = $this->tokens->realmId();
        $company = $this->get('/companyinfo/'.$realm, [], null, 'companyinfo');
        $prefs = $this->get('/preferences', [], null, 'preferences');
        $companyInfo = is_array($company['CompanyInfo'] ?? null) ? $company['CompanyInfo'] : [];
        $preferences = is_array($prefs['Preferences'] ?? null) ? $prefs['Preferences'] : [];
        $currencyPrefs = is_array($preferences['CurrencyPrefs'] ?? null) ? $preferences['CurrencyPrefs'] : [];
        $taxPrefs = is_array($preferences['TaxPrefs'] ?? null) ? $preferences['TaxPrefs'] : [];

        $ap = self::normalizeList(data_get($this->query(
            "select Id, Name, AccountType from Account where AccountType = 'Accounts Payable' maxresults 100",
            null,
            'inspect_ap_accounts',
        ), 'QueryResponse.Account'));
        $expense = self::normalizeList(data_get($this->query(
            "select Id, Name, AccountType from Account where AccountType = 'Expense' maxresults 100",
            null,
            'inspect_expense_accounts',
        ), 'QueryResponse.Account'));
        $items = self::normalizeList(data_get($this->query(
            'select Id, Name, Type, ExpenseAccountRef from Item maxresults 100',
            null,
            'inspect_items',
        ), 'QueryResponse.Item'));
        $vendors = self::normalizeList(data_get($this->query(
            'select * from Vendor maxresults 3',
            null,
            'inspect_vendors',
        ), 'QueryResponse.Vendor'));
        $purchaseOrders = self::normalizeList(data_get($this->query(
            'select * from PurchaseOrder maxresults 20',
            null,
            'inspect_purchase_orders',
        ), 'QueryResponse.PurchaseOrder'));

        $docNumbers = [];
        foreach ($purchaseOrders as $po) {
            if (isset($po['DocNumber']) && (string) $po['DocNumber'] !== '') {
                $docNumbers[] = (string) $po['DocNumber'];
            }
        }

        return [
            'company' => [
                'name' => $companyInfo['CompanyName'] ?? null,
                'country' => $companyInfo['Country'] ?? null,
                'structure' => self::structureSummary($companyInfo),
            ],
            'preferences' => [
                'home_currency' => data_get($currencyPrefs, 'HomeCurrency.value') ?? ($currencyPrefs['HomeCurrency'] ?? null),
                'multi_currency' => $currencyPrefs['MultiCurrencyEnabled'] ?? null,
                'tax' => [
                    'UsingSalesTax' => $taxPrefs['UsingSalesTax'] ?? null,
                    'PartnerTaxEnabled' => $taxPrefs['PartnerTaxEnabled'] ?? null,
                    'structure' => self::structureSummary($taxPrefs),
                ],
                'structure' => self::structureSummary($preferences),
            ],
            'ap_accounts' => $this->trimAccounts($ap),
            'expense_accounts' => $this->trimAccounts($expense),
            'items' => array_map(function (array $item) {
                return [
                    'Id' => $item['Id'] ?? null,
                    'Name' => $item['Name'] ?? null,
                    'Type' => $item['Type'] ?? null,
                    'ExpenseAccountRef' => data_get($item, 'ExpenseAccountRef.value'),
                ];
            }, $items),
            'vendors' => array_map(fn (array $vendor) => [
                'Id' => $vendor['Id'] ?? null,
                'DisplayName' => $vendor['DisplayName'] ?? null,
                'structure' => self::structureSummary($vendor),
            ], $vendors),
            'purchase_orders' => array_map(fn (array $po) => [
                'Id' => $po['Id'] ?? null,
                'DocNumber' => $po['DocNumber'] ?? null,
                'POStatus' => $po['POStatus'] ?? null,
                'structure' => self::structureSummary($po),
            ], array_slice($purchaseOrders, 0, 3)),
            'doc_numbers' => $docNumbers,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inspectVendors(int $limit = 10): array
    {
        $limit = max(1, min($limit, 10));
        $rows = self::normalizeList(data_get($this->query(
            "select Id, DisplayName, CompanyName, PrimaryEmailAddr, Active from Vendor maxresults {$limit}",
            null,
            'inspect_vendors',
        ), 'QueryResponse.Vendor'));

        return array_map(fn (array $row) => [
            'id' => isset($row['Id']) ? (string) $row['Id'] : null,
            'display_name' => $row['DisplayName'] ?? null,
            'company_name' => $row['CompanyName'] ?? null,
            'email' => data_get($row, 'PrimaryEmailAddr.Address'),
            'active' => $row['Active'] ?? null,
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inspectPurchaseOrders(int $limit = 10): array
    {
        $limit = max(1, min($limit, 10));
        $rows = self::normalizeList(data_get($this->query(
            "select Id, DocNumber, POStatus, TxnDate, TotalAmt, VendorRef, CurrencyRef from PurchaseOrder maxresults {$limit}",
            null,
            'inspect_purchase_orders',
        ), 'QueryResponse.PurchaseOrder'));

        return array_map(fn (array $row) => [
            'id' => isset($row['Id']) ? (string) $row['Id'] : null,
            'doc_number' => $row['DocNumber'] ?? null,
            'po_status' => $row['POStatus'] ?? null,
            'txn_date' => $row['TxnDate'] ?? null,
            'total_amt' => $row['TotalAmt'] ?? null,
            'vendor_ref' => data_get($row, 'VendorRef.value'),
            'currency' => data_get($row, 'CurrencyRef.value'),
        ], $rows);
    }

    public static function escapeQueryLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * QuickBooks returns one object when a query has a single row, and a list when it has several.
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeList(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        if (array_is_list($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        return [$value];
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<string, bool>
     */
    public static function structureSummary(array $entity): array
    {
        $summary = [];
        foreach ($entity as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $summary[$key] = self::isPopulated($value);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, array $query, bool $retried, ?int $mrfId, string $operation): array
    {
        if (str_contains($path, '?')) {
            throw new QuickBooksException('QuickBooks paths must not include a query string.');
        }

        $query = array_merge([
            'minorversion' => (int) config('services.quickbooks.minor_version', 75),
        ], $query);

        $url = rtrim($this->discovery->apiBaseUrl(), '/')
            .'/v3/company/'
            .rawurlencode($this->tokens->realmId())
            .'/'.ltrim($path, '/');

        try {
            $pending = Http::withToken($this->tokens->accessToken())
                ->acceptJson()
                ->asJson()
                ->timeout(30);

            $response = $method === 'POST'
                ? $pending->withQueryParameters($query)->post($url, $body ?? [])
                : $pending->get($url, $query);
        } catch (ConnectionException) {
            $this->logFailure($operation, $mrfId, null, null, null);
            throw new QuickBooksException('QuickBooks could not be reached.', status: 502);
        }

        $intuitTid = $this->header($response->header('intuit_tid'));

        if ($response->status() === 401 && ! $retried) {
            $this->tokens->refresh(true);

            return $this->request($method, $path, $body, $query, true, $mrfId, $operation);
        }

        if ($response->status() === 404 || $this->faultCode($response) === '610') {
            return [];
        }

        if (! $response->successful()) {
            $fault = $this->faultCode($response);
            $this->logFailure($operation, $mrfId, $response->status(), $fault, $intuitTid);
            if ($fault === '6240') {
                throw new QuickBooksException(
                    'QuickBooks already has a vendor, customer, or employee with this DisplayName. DisplayName must be unique across those lists.',
                    $response->status(),
                    $fault,
                    $intuitTid,
                    409,
                );
            }

            throw new QuickBooksException(
                'QuickBooks request failed.',
                $response->status(),
                $fault,
                $intuitTid,
                $response->status() >= 500 ? 502 : 422,
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(string $entity, string $id, ?int $mrfId = null, string $operation = 'read'): ?array
    {
        if ($id === '') {
            return null;
        }

        $json = $this->get('/'.$entity.'/'.rawurlencode($id), [], $mrfId, $operation);
        if ($json === []) {
            return null;
        }

        $wrapper = $entity === 'purchaseorder' ? 'PurchaseOrder' : ucfirst($entity);
        if (! isset($json[$wrapper]) && ! isset($json['Id'])) {
            return null;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $po
     * @return array{Id: string, SyncToken: string, DocNumber: ?string, POStatus: string}
     */
    private function trimPurchaseOrder(array $po): array
    {
        return [
            'Id' => (string) ($po['Id'] ?? ''),
            'SyncToken' => (string) ($po['SyncToken'] ?? ''),
            'DocNumber' => isset($po['DocNumber']) ? (string) $po['DocNumber'] : null,
            'POStatus' => (string) ($po['POStatus'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array{Id: mixed, Name: mixed, AccountType: mixed}>
     */
    private function trimAccounts(array $accounts): array
    {
        return array_map(fn (array $account) => [
            'Id' => $account['Id'] ?? null,
            'Name' => $account['Name'] ?? null,
            'AccountType' => $account['AccountType'] ?? null,
        ], $accounts);
    }

    private function faultCode(Response $response): ?string
    {
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $code = data_get($json, 'Fault.Error.0.code');

        return $code === null || $code === '' ? null : (string) $code;
    }

    private function logFailure(string $operation, ?int $mrfId, ?int $status, ?string $faultCode, ?string $intuitTid): void
    {
        Log::warning('QuickBooks request failed', [
            'operation' => $operation,
            'mrf_id' => $mrfId,
            'http_status' => $status,
            'fault_code' => $faultCode,
            'intuit_tid' => $intuitTid,
        ]);
    }

    private function header(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function isPopulated(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        return true;
    }
}
