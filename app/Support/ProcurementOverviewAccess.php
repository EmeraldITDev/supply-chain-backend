<?php

namespace App\Support;

use App\Models\User;

/**
 * Logistics Manager procurement overview — read-only access to procurement dashboards
 * and listings without workflow mutation rights.
 */
class ProcurementOverviewAccess
{
    /** Roles that may open /procurement (read-only overview). */
    public const OVERVIEW_ROLES = [
        'logistics_manager',
        'logistics',
    ];

    /** Roles with full procurement management (not read-only). */
    public const MANAGEMENT_ROLES = [
        'procurement_manager',
        'procurement',
        'procurement_officer',
        'supply_chain_director',
        'supply_chain',
        'executive',
        'chairman',
        'admin',
    ];

    /** Roles that may generate/upload GRN and delivery confirmation documents (JCC, waybill, etc.). */
    public const DELIVERY_DOCUMENT_ROLES = [
        'procurement',
        'procurement_manager',
        'procurement_officer',
        'supply_chain_director',
        'supply_chain',
        'logistics_manager',
        'logistics',
        'logistics_officer',
        'admin',
    ];

    public static function canManageDeliveryDocuments(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($user->scmRole(), self::DELIVERY_DOCUMENT_ROLES, true);
    }

    /**
     * GRN preview/generate is allowed for delivery document roles even when
     * {@see isProcurementOverviewOnly()} is true (procurement overview is otherwise read-only).
     */
    public static function canGenerateGrnDocuments(?User $user): bool
    {
        return self::canManageDeliveryDocuments($user);
    }

    public static function isProcurementOverviewOnly(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($user->scmRole(), self::OVERVIEW_ROLES, true);
    }

    public static function canAccessProcurementPage(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $role = $user->scmRole();

        return in_array($role, array_merge(self::MANAGEMENT_ROLES, self::OVERVIEW_ROLES), true)
            || in_array($role, ['logistics_officer'], true);
    }

    /**
     * @return list<string>
     */
    public static function readOnlyDocumentTypes(): array
    {
        return [
            'mrf',
            'grn',
            'waybill',
            'jcc',
            'vendor_invoice',
            'delivery_confirmation',
            'pfi',
            'po_pdf',
            'signed_po',
            'other',
        ];
    }
}
