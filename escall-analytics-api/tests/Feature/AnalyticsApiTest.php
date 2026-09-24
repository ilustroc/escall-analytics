<?php

namespace Tests\Feature;

use App\Models\AnalyticsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_service_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'ESCALL Analytics API',
            ]);
    }

    public function test_expertis_dashboard_rejects_an_inverted_date_range(): void
    {
        $this->authenticate(AnalyticsUser::ROLE_PROVEEDOR);
        $this->getJson('/api/v1/expertis/dashboard?fechaDesde=2026-09-14&fechaHasta=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fechaHasta');
    }

    public function test_gestiones_rejects_an_invalid_page_size(): void
    {
        $this->authenticate(AnalyticsUser::ROLE_PROVEEDOR);
        $this->getJson('/api/v1/expertis/gestiones?perPage=30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('perPage');
    }

    public function test_gestiones_rejects_an_invalid_sort_field(): void
    {
        $this->authenticate(AnalyticsUser::ROLE_PROVEEDOR);
        $this->getJson('/api/v1/expertis/gestiones?sortField=drop_table')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sortField');
    }

    public function test_supervisor_cannot_access_user_administration(): void
    {
        $this->authenticate(AnalyticsUser::ROLE_SUPERVISOR);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_provider_cannot_access_operativo_or_imports(): void
    {
        $this->authenticate(AnalyticsUser::ROLE_PROVEEDOR);
        $this->getJson('/api/v1/operativo/dashboard')->assertForbidden();
        $this->getJson('/api/v1/importaciones')->assertForbidden();
    }

    public function test_valid_login_returns_the_authenticated_analytics_user(): void
    {
        $user = AnalyticsUser::factory()->create(['email' => 'valid@example.test']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password!12345'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.role', AnalyticsUser::ROLE_PROVEEDOR);
    }

    public function test_invalid_or_inactive_login_is_not_authenticated(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => 'Password!12345'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Las credenciales ingresadas no son válidas.');

        $inactive = AnalyticsUser::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $inactive->email, 'password' => 'Password!12345'])->assertForbidden();
    }

    public function test_must_change_password_blocks_dashboards_and_can_be_completed(): void
    {
        $user = AnalyticsUser::factory()->create(['must_change_password' => true]);
        $this->actingAs($user, 'analytics');

        $this->getJson('/api/v1/expertis/dashboard')->assertForbidden()->assertJsonPath('mustChangePassword', true);
        $this->postJson('/api/v1/auth/change-password', ['password' => 'NuevaClave!2026', 'password_confirmation' => 'NuevaClave!2026'])
            ->assertOk()
            ->assertJsonPath('user.mustChangePassword', false);
    }

    public function test_login_is_rate_limited_after_five_attempts_per_email_and_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'rate@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'rate@example.test', 'password' => 'incorrect'])->assertTooManyRequests();
    }

    private function authenticate(string $role): AnalyticsUser
    {
        $user = AnalyticsUser::factory()->create(['role' => $role]);
        $this->actingAs($user, 'analytics');

        return $user;
    }
}
