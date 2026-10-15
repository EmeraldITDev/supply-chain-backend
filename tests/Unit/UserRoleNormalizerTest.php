<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\UserRoleNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleNormalizerTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $attrs */
    private function user(array $attrs): User
    {
        // role / hris_role are intentionally not mass-assignable on User.
        return (new User)->forceFill($attrs);
    }

    public function test_normalizes_display_labels_to_canonical_keys(): void
    {
        $this->assertSame('logistics_manager', UserRoleNormalizer::normalize('Logistics Manager'));
        $this->assertSame('procurement_manager', UserRoleNormalizer::normalize('Procurement'));
        $this->assertSame('supply_chain_director', UserRoleNormalizer::normalize('Supply Chain Director'));
    }

    public function test_supply_chain_role_is_read_exclusively_for_permissions(): void
    {
        $user = $this->user([
            'hris_role' => 'corporate_hr',
            'supply_chain_role' => 'procurement_manager',
            'role' => 'employee',
        ]);

        $this->assertSame('procurement_manager', UserRoleNormalizer::supplyChainRole($user));
        $this->assertTrue(UserRoleNormalizer::hasSupplyChainRole($user, 'procurement_manager'));
        $this->assertFalse(UserRoleNormalizer::hasSupplyChainRole($user, 'corporate_hr'));
    }

    public function test_logistics_manager_display_label_grants_scm_login_access(): void
    {
        $user = $this->user([
            'supply_chain_role' => 'Logistics Manager',
            'is_admin' => false,
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_admin_flag_grants_scm_login_access_without_canonical_role(): void
    {
        $user = $this->user([
            'supply_chain_role' => 'custom_role',
            'is_admin' => true,
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_user_department_grants_access_without_role(): void
    {
        $user = $this->user([
            'supply_chain_role' => null,
            'department' => 'Supply Chain',
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_designated_requisition_creator_grants_access(): void
    {
        $user = $this->user([
            'supply_chain_role' => null,
            'designated_requisition_creator' => true,
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_role_keyword_procurement_officer_grants_access(): void
    {
        $user = $this->user([
            'supply_chain_role' => 'senior_procurement_officer',
        ]);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_vendor_account_denied_main_scm_login(): void
    {
        $user = $this->user([
            'supply_chain_role' => 'vendor',
            'vendor_id' => 99,
        ]);

        $this->assertFalse(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
        $this->assertTrue(UserRoleNormalizer::isVendorAccount($user));
    }

    public function test_infer_role_from_department(): void
    {
        $user = $this->user([
            'supply_chain_role' => 'user',
            'department' => 'Logistics',
        ]);

        $this->assertSame('logistics_manager', UserRoleNormalizer::inferCanonicalRoleFromProfile($user));
    }

    public function test_hr_manager_grants_scm_login_access(): void
    {
        $user = $this->user(['supply_chain_role' => 'hr_manager']);

        $this->assertTrue(UserRoleNormalizer::hasSupplyChainLoginAccess($user));
    }

    public function test_spatie_sync_roles_constant_is_public(): void
    {
        $this->assertContains('hr_manager', UserRoleNormalizer::SPATIE_SYNC_ROLES);
    }

    public function test_legacy_role_column_used_only_when_supply_chain_role_empty(): void
    {
        $user = $this->user([
            'supply_chain_role' => null,
            'role' => 'finance',
        ]);

        $this->assertSame('finance', UserRoleNormalizer::supplyChainRole($user));
    }

    public function test_hris_only_role_in_supply_chain_role_is_ignored_at_read_time(): void
    {
        $user = $this->user([
            'supply_chain_role' => 'corporate_hr',
            'role' => 'procurement_manager',
        ]);

        $this->assertSame('procurement_manager', UserRoleNormalizer::supplyChainRole($user));
    }

    public function test_is_hris_only_role_detects_corporate_hr(): void
    {
        $this->assertTrue(UserRoleNormalizer::isHrisOnlyRole('corporate_hr'));
        $this->assertTrue(UserRoleNormalizer::isHrisOnlyRole('general_employee'));
        $this->assertTrue(UserRoleNormalizer::isHrisOnlyRole('line_manager'));
        $this->assertFalse(UserRoleNormalizer::isHrisOnlyRole('procurement_manager'));
    }

    public function test_general_employee_legacy_role_is_not_treated_as_scm_role(): void
    {
        $user = $this->user([
            'supply_chain_role' => null,
            'role' => 'general_employee',
            'hris_role' => 'general_employee',
        ]);

        $this->assertNull(UserRoleNormalizer::supplyChainRole($user));
    }

    public function test_resolve_scm_role_defaults_employee_for_hris_linked_staff(): void
    {
        $user = $this->user([
            'hris_role' => 'corporate_hr',
            'supply_chain_role' => null,
            'employee_id' => 99,
        ]);
        $user->setRelation('employee', null);

        $this->assertSame('employee', UserRoleNormalizer::resolveScmRoleForRepair($user));
    }
}
