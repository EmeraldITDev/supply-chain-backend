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
}
