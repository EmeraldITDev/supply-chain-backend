<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * HRIS and SCM share users + Spatie model_has_roles. HRIS syncRoles() must not
 * make SCM login report an HRIS role as supply_chain_role.
 */
class AuthScmRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_supply_chain_role_not_hris_spatie_role(): void
    {
        $user = User::factory()->create([
            'email' => 'viva.musa@example.com',
            'password' => bcrypt('password'),
            'supply_chain_role' => 'procurement_manager',
            'role' => 'general_employee',
            'hris_role' => 'general_employee',
        ]);

        Role::findOrCreate('general_employee', 'web');
        $user->syncRoles(['general_employee']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'viva.musa@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.supply_chain_role', 'procurement_manager')
            ->assertJsonPath('user.role', 'procurement_manager')
            ->assertJsonPath('user.hris_role', 'general_employee');
    }

    public function test_me_returns_supply_chain_role_not_hris_spatie_role(): void
    {
        $user = User::factory()->create([
            'supply_chain_role' => 'logistics_manager',
            'role' => 'general_employee',
            'hris_role' => 'general_employee',
        ]);

        Role::findOrCreate('general_employee', 'web');
        $user->syncRoles(['general_employee']);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('supply_chain_role', 'logistics_manager')
            ->assertJsonPath('role', 'logistics_manager')
            ->assertJsonPath('hris_role', 'general_employee');
    }

    public function test_legacy_general_employee_is_not_treated_as_scm_role(): void
    {
        $user = User::factory()->create([
            'supply_chain_role' => null,
            'role' => 'general_employee',
            'hris_role' => 'general_employee',
            'department' => 'Operations',
        ]);

        Role::findOrCreate('general_employee', 'web');
        $user->syncRoles(['general_employee']);

        Sanctum::actingAs($user);

        // No SCM role set — auth may fall back to employee for UI, never general_employee.
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('supply_chain_role', 'employee');
    }
}
