<?php

namespace App\Support;

use App\Models\User;

/**
 * Roles that may perform Procurement Manager operational work
 * (RFQ, quotations, vendor selection, PO draft/generate, force-close, etc.).
 *
 * Supply Chain Director is included so SCD retains director-only approvals
 * while also having every PM operational capability. Workflow stages still
 * require their own approval steps — this list does not auto-approve or skip
 * required approvers.
 */
final class ProcurementOperationalRoles
{
    /**
     * @var list<string>
     */
    public const ROLES = [
        'procurement_manager',
        'procurement',
        'procurement_officer',
        'supply_chain_director',
        'supply_chain',
        'admin',
    ];

    /**
     * Roles that may force-close a purchase order (exception path).
     * Matches the historical PM manager + admin gate, plus SCD.
     *
     * @var list<string>
     */
    public const FORCE_CLOSE_ROLES = [
        'procurement_manager',
        'supply_chain_director',
        'supply_chain',
        'admin',
    ];

    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (($user->is_admin ?? false) === true) {
            return true;
        }

        $role = UserRoleNormalizer::normalize(UserRoleNormalizer::supplyChainRole($user));

        return is_string($role) && in_array($role, self::ROLES, true);
    }

    public static function allowsForceClose(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (($user->is_admin ?? false) === true) {
            return true;
        }

        $role = UserRoleNormalizer::normalize(UserRoleNormalizer::supplyChainRole($user));

        return is_string($role) && in_array($role, self::FORCE_CLOSE_ROLES, true);
    }

    /**
     * @return list<string>
     */
    public static function withoutAdmin(): array
    {
        return array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => $role !== 'admin'
        ));
    }
}
