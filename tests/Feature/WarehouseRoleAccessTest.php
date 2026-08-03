<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PermissionService;
use App\Support\UserRoleNormalizer;
use Tests\TestCase;

class WarehouseRoleAccessTest extends TestCase
{
    public function test_warehouse_manager_is_recognized_as_valid_scm_role_and_can_manage_users(): void
    {
        $user = new User([
            'name' => 'Warehouse Manager',
            'email' => 'warehouse.manager@example.com',
            'supply_chain_role' => 'warehouse_manager',
            'is_admin' => false,
            'can_manage_users' => false,
        ]);

        $this->assertTrue(UserRoleNormalizer::isValidScmRoleKey('warehouse_manager'));
        $this->assertTrue(UserRoleNormalizer::hasSupplyChainRole($user, ['warehouse_manager']));

        $permissionService = app(PermissionService::class);
        $this->assertTrue($permissionService->canManageUsers($user));
        $this->assertTrue($permissionService->canListUsersDirectory($user));
    }
}
