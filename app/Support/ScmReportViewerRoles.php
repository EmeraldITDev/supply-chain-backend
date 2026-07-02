<?php

namespace App\Support;

/**
 * SCM roles with read access to analytics / procurement report endpoints.
 */
final class ScmReportViewerRoles
{
    public const ROLES = [
        'procurement_manager',
        'procurement',
        'supply_chain_director',
        'supply_chain',
        'admin',
        'finance',
        'finance_officer',
        'executive',
        'logistics_manager',
        'logistics_officer',
    ];

    public static function allows(?string $role): bool
    {
        return $role !== null && in_array($role, self::ROLES, true);
    }
}
