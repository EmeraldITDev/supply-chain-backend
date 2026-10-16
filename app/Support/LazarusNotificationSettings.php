<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\User;

class LazarusNotificationSettings
{
    public const CATEGORY = 'lazarus_notifications';

    public const EMAIL = 'lazarus.angbazo@emeraldcfze.com';

    /**
     * Each switch is off until Supply Chain or Procurement turns it on.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const TOGGLES = [
        'mrf_submitted' => [
            'label' => 'New MRF submitted',
            'description' => 'Email him when a material request is submitted, approved, or rejected.',
        ],
        'mrf_requester_updated' => [
            'label' => 'MRF edited by the requester',
            'description' => 'Email him when a requester changes an open material request.',
        ],
        'vendor_selection_approved' => [
            'label' => 'Vendor selection approved',
            'description' => 'Email him when a vendor is approved and a purchase order still has to be created.',
        ],
        'po_generated' => [
            'label' => 'Purchase order generated',
            'description' => 'Email him when a purchase order is generated or is waiting for a signature.',
        ],
        'po_signed' => [
            'label' => 'Purchase order signed',
            'description' => 'Email him when a purchase order is signed.',
        ],
        'po_revised' => [
            'label' => 'Signed purchase order revised',
            'description' => 'Email him when a signed purchase order is sent back for a new signature.',
        ],
        'approval_reminders' => [
            'label' => 'Overdue approval reminders',
            'description' => 'Email him when a request has been waiting for approval.',
        ],
    ];

    /** @var array<string, bool>|null */
    private static ?array $enabledCache = null;

    public static function isLazarusEmail(?string $email): bool
    {
        return strcasecmp(trim((string) $email), self::EMAIL) === 0;
    }

    public static function canManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return in_array($user->scmRole(), [
            'supply_chain_director',
            'supply_chain',
            'procurement_manager',
            'procurement',
            'admin',
        ], true);
    }

    public static function flush(): void
    {
        self::$enabledCache = null;
    }

    public static function enabled(string $key): bool
    {
        if (! array_key_exists($key, self::TOGGLES)) {
            return false;
        }

        self::$enabledCache ??= self::loadEnabledMap();

        return self::$enabledCache[$key] ?? false;
    }

    /**
     * @return list<array{key: string, label: string, description: string, enabled: bool}>
     */
    public static function all(): array
    {
        self::$enabledCache ??= self::loadEnabledMap();

        $toggles = [];
        foreach (self::TOGGLES as $key => $meta) {
            $toggles[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => self::$enabledCache[$key] ?? false,
            ];
        }

        return $toggles;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{key: string, label: string, description: string, enabled: bool}>
     */
    public static function save(array $input): array
    {
        foreach (self::TOGGLES as $key => $meta) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            SystemSetting::query()->updateOrCreate(
                ['category' => self::CATEGORY, 'key' => $key],
                ['value' => filter_var($input[$key], FILTER_VALIDATE_BOOLEAN) ? '1' : '0'],
            );
        }

        self::flush();

        return self::all();
    }

    /**
     * @return array<string, bool>
     */
    private static function loadEnabledMap(): array
    {
        $stored = SystemSetting::query()
            ->where('category', self::CATEGORY)
            ->pluck('value', 'key');

        $enabled = [];
        foreach (array_keys(self::TOGGLES) as $key) {
            $raw = strtolower(trim((string) ($stored[$key] ?? '0')));
            $enabled[$key] = in_array($raw, ['1', 'true', 'yes', 'on'], true);
        }

        return $enabled;
    }
}
