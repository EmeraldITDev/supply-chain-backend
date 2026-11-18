<?php

namespace App\Support;

use App\Models\User;

/**
 * Shared QuickBooks role gates. Keep connection management in one place only.
 */
final class QuickBooksRoles
{
    /**
     * Connect, Disconnect, and Inspect (accounts/items/vendors/purchase orders).
     *
     * @var list<string>
     */
    public const CONNECTION_ROLES = [
        'admin',
        'procurement_manager',
        'supply_chain_director',
        'supply_chain',
    ];

    /**
     * Sync purchase orders and pull vendors / PO status.
     *
     * @var list<string>
     */
    public const SYNC_ROLES = [
        'procurement_manager',
        'procurement',
        'supply_chain_director',
        'supply_chain',
        'admin',
    ];

    public const SUPPLIER_ROLES = [
        'admin',
        'finance',
        'finance_officer',
        'procurement_manager',
        'supply_chain_director',
    ];

    public static function syncMiddleware(): string
    {
        return 'role:'.implode(',', self::SYNC_ROLES);
    }

    public static function supplierMiddleware(): string
    {
        return 'role:'.implode(',', self::SUPPLIER_ROLES);
    }

    /**
     * Exact match against CONNECTION_ROLES. Does not promote procurement → manager.
     */
    public static function userCanManageConnection(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! empty($user->is_admin)) {
            return true;
        }

        $role = UserRoleNormalizer::supplyChainRole($user);

        return is_string($role) && in_array($role, self::CONNECTION_ROLES, true);
    }
}
