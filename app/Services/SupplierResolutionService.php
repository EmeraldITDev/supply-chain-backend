<?php

namespace App\Services;

use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierResolutionService
{
    /**
     * @return array{status: string, vendor: ?Vendor, matched_on?: string, reason?: string, message?: string}
     */
    public function resolve(string $name, string $email): array
    {
        $normalizedName = Vendor::normalizeName($name);
        $normalizedEmail = Vendor::normalizeEmail($email);

        $emailMatches = $normalizedEmail === ''
            ? collect()
            : Vendor::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
                ->orderBy('id')
                ->get();
        $nameMatches = $normalizedName === ''
            ? collect()
            : $this->findNameMatches($normalizedName);

        if ($emailMatches->count() > 1) {
            return $this->reviewResult('Multiple suppliers share the normalized email address.');
        }

        if ($emailMatches->count() === 1) {
            $emailVendor = $emailMatches->first();
            if ($nameMatches->count() === 0) {
                return $this->matchedResult($emailVendor, 'email');
            }
            if ($nameMatches->count() === 1 && $nameMatches->first()->id === $emailVendor->id) {
                return $this->matchedResult($emailVendor, 'email_and_name');
            }

            return $this->reviewResult('The normalized email and company name identify different suppliers.');
        }

        if ($nameMatches->count() > 1) {
            return $this->reviewResult('Multiple suppliers share the normalized company name.');
        }

        if ($nameMatches->count() === 1) {
            $nameVendor = $nameMatches->first();
            $existingEmail = Vendor::normalizeEmail((string) $nameVendor->email);
            if ($normalizedEmail !== '' && $this->isReliableEmail($existingEmail) && $existingEmail !== $normalizedEmail) {
                return $this->reviewResult('The company name matches a supplier with a different email address.');
            }

            return $this->matchedResult($nameVendor, 'name');
        }

        return [
            'status' => 'unmatched',
            'vendor' => null,
        ];
    }

    /**
     * Resolve and create atomically when no supplier matches.
     *
     * @param  callable(string, string): Vendor  $create
     * @param  callable(Vendor): void|null  $onMatch
     * @return array{status: string, vendor: ?Vendor, matched_on?: string, reason?: string, message?: string}
     */
    public function resolveOrCreate(
        string $name,
        string $email,
        callable $create,
        ?callable $onMatch = null,
    ): array {
        $normalizedEmail = Vendor::normalizeEmail($email);
        $normalizedName = Vendor::normalizeName($name);
        if ($normalizedEmail === '' || $normalizedName === '') {
            throw new \InvalidArgumentException('Supplier name and email are required for identity resolution.');
        }

        try {
            return $this->withIdentityLocks($normalizedName, $normalizedEmail, function () use (
                $name,
                $normalizedEmail,
                $create,
                $onMatch,
            ) {
                return DB::transaction(function () use ($name, $normalizedEmail, $create, $onMatch) {
                    $resolved = $this->resolve($name, $normalizedEmail);
                    if ($resolved['status'] !== 'unmatched') {
                        if ($resolved['status'] === 'matched' && $onMatch !== null) {
                            $onMatch($resolved['vendor']);
                        }

                        return $resolved;
                    }

                    return [
                        'status' => 'created',
                        'vendor' => $create(trim($name), $normalizedEmail),
                    ];
                });
            });
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $resolved = $this->resolve($name, $normalizedEmail);
            if ($resolved['status'] === 'matched') {
                if ($onMatch !== null) {
                    $onMatch($resolved['vendor']);
                }

                return $resolved;
            }

            throw $exception;
        }
    }

    /**
     * @return Collection<int, Vendor>
     */
    private function findNameMatches(string $normalizedName): Collection
    {
        return Vendor::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Vendor $vendor) => Vendor::normalizeName($vendor->name) === $normalizedName)
            ->values();
    }

    /**
     * @return array{status: string, vendor: Vendor, matched_on: string}
     */
    private function matchedResult(Vendor $vendor, string $matchedOn): array
    {
        return [
            'status' => 'matched',
            'vendor' => $vendor,
            'matched_on' => $matchedOn,
        ];
    }

    /**
     * @return array{status: string, vendor: null, reason: string, message: string}
     */
    private function reviewResult(string $message): array
    {
        return [
            'status' => 'review_required',
            'vendor' => null,
            'reason' => 'supplier_identity_ambiguous',
            'message' => $message,
        ];
    }

    private function isReliableEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && ! str_contains(strtolower($email), '@supplier.placeholder');
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }

    private function withIdentityLocks(string $normalizedName, string $normalizedEmail, callable $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return $callback();
        }

        $lockNames = [
            'scm_supplier_'.substr(hash('sha256', 'email:'.$normalizedEmail), 0, 48),
            'scm_supplier_'.substr(hash('sha256', 'name:'.$normalizedName), 0, 48),
        ];
        sort($lockNames);
        $acquired = [];

        foreach (array_unique($lockNames) as $lockName) {
            $lock = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if ((int) ($lock->acquired ?? 0) !== 1) {
                foreach (array_reverse($acquired) as $acquiredName) {
                    $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$acquiredName]);
                }

                throw new \RuntimeException('Supplier identity is busy. Please retry the request.');
            }
            $acquired[] = $lockName;
        }

        try {
            return $callback();
        } finally {
            foreach (array_reverse($acquired) as $lockName) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        }
    }
}
