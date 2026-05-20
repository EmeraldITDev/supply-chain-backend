<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\UserRoleNormalizer;
use PHPUnit\Framework\TestCase;

class UserRoleNormalizerTest extends TestCase
{
    public function test_normalizes_display_labels_to_canonical_keys(): void
    {
        $this->assertSame('logistics_manager', UserRoleNormalizer::normalize('Logistics Manager'));
        $this->assertSame('procurement_manager', UserRoleNormalizer::normalize('Procurement'));
        $this->assertSame('supply_chain_director', UserRoleNormalizer::normalize('Supply Chain Director'));
    }

    public function test_logistics_manager_display_label_grants_scm_login_access(): void
    {
        $user = new User([
            'role' => 'Logistics Manager',
            'is_admin' => false,
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_admin_flag_grants_scm_login_access_without_canonical_role(): void
    {
        $user = new User([
            'role' => 'custom_role',
            'is_admin' => true,
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_user_department_grants_access_without_role(): void
    {
        $user = new User([
            'role' => null,
            'department' => 'Supply Chain',
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_designated_requisition_creator_grants_access(): void
    {
        $user = new User([
            'role' => null,
            'designated_requisition_creator' => true,
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_role_keyword_procurement_officer_grants_access(): void
    {
        $user = new User([
            'role' => 'senior_procurement_officer',
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_vendor_account_denied_main_scm_login(): void
    {
        $user = new User([
            'role' => 'vendor',
            'vendor_id' => 99,
        ]);

        $this->assertFalse(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
        $this->assertTrue(UserRoleNormalizer::isVendorAccount($user));
    }

    public function test_infer_role_from_department(): void
    {
        $user = new User([
            'role' => 'user',
            'department' => 'Logistics',
        ]);

        $this->assertSame('logistics_manager', UserRoleNormalizer::inferCanonicalRoleFromProfile($user));
    }
}
