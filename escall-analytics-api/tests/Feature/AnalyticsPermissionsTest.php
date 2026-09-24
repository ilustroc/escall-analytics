<?php

namespace Tests\Feature;

use App\Models\AnalyticsUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AnalyticsPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->getPdo()->sqliteCreateFunction('regexp', static fn (string $pattern, ?string $value): int => preg_match('/'.$pattern.'/', $value ?? ''));
        DB::connection()->getPdo()->sqliteCreateFunction('left', static fn (?string $value, int $length): string => substr($value ?? '', 0, $length));
        DB::connection()->getPdo()->sqliteCreateFunction('hour', static fn (?string $value): int => (int) substr($value ?? '0', 0, 2));
        $this->createSourceTables();
        $this->seedSourceRows();
    }

    public function test_administrator_can_access_all_analytics_api_modules(): void
    {
        $this->actingAs(AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_ADMINISTRADOR]), 'analytics');

        $this->getJson('/api/v1/expertis/dashboard?fechaDesde=2026-09-01&fechaHasta=2026-09-14')->assertOk();
        $this->getJson('/api/v1/operativo/dashboard?fecha=2026-09-02')->assertOk();
        $this->getJson('/api/v1/reportes/gestiones?fechaDesde=2026-09-01&fechaHasta=2026-09-14')->assertOk();
        $this->getJson('/api/v1/imports/config?period=202609')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_supervisor_can_access_operational_modules_but_not_user_administration(): void
    {
        $this->actingAs(AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_SUPERVISOR]), 'analytics');

        $this->getJson('/api/v1/expertis/dashboard?fechaDesde=2026-09-01&fechaHasta=2026-09-14')->assertOk();
        $this->getJson('/api/v1/operativo/dashboard?fecha=2026-09-02')->assertOk();
        $this->getJson('/api/v1/reportes/gestiones?fechaDesde=2026-09-01&fechaHasta=2026-09-14')->assertOk();
        $this->getJson('/api/v1/imports/config?period=202609')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_provider_can_access_only_expertis_dashboard(): void
    {
        $this->actingAs(AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_PROVEEDOR]), 'analytics');

        $this->getJson('/api/v1/expertis/dashboard?fechaDesde=2026-09-01&fechaHasta=2026-09-14')->assertOk();
        $this->getJson('/api/v1/operativo/dashboard?fecha=2026-09-02')->assertForbidden();
        $this->getJson('/api/v1/reportes/gestiones?fechaDesde=2026-09-01&fechaHasta=2026-09-14')->assertForbidden();
        $this->getJson('/api/v1/imports/config?period=202609')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    private function createSourceTables(): void
    {
        Schema::create('gestiones', static function (Blueprint $table): void {
            $table->id();
            $table->date('fecha_gestion');
            $table->string('dni');
            $table->string('dni_normalizado');
            $table->string('telefono')->nullable();
            $table->string('status')->nullable();
            $table->string('tipificacion');
            $table->string('observacion')->nullable();
            $table->date('fecha_pago')->nullable();
            $table->decimal('monto_pago', 12, 2)->nullable();
            $table->string('nombre')->nullable();
        });
        Schema::create('Asignacion_Expertis_Historico', static function (Blueprint $table): void {
            $table->string('FEC_PRO', 6);
            $table->string('Documento_RUC');
            $table->string('documento_normalizado');
            $table->string('NombreDeudor')->nullable();
            $table->string('CARTERA')->nullable();
            $table->string('cosecha')->nullable();
            $table->string('Canal')->nullable();
            $table->string('conca')->nullable();
        });
        Schema::create('pagos_expertis', static function (Blueprint $table): void {
            $table->id();
            $table->date('fecha');
            $table->string('cuenta_normalizada');
            $table->string('dni')->nullable();
            $table->decimal('monto', 14, 2);
            $table->string('ejecutivo')->nullable();
            $table->string('tipo_acuerdo')->nullable();
        });
        Schema::create('tipificaciones_expertis', static function (Blueprint $table): void {
            $table->string('tipificacion');
            $table->string('gestion');
            $table->unsignedInteger('peso');
            $table->boolean('activo')->default(true);
        });
    }

    private function seedSourceRows(): void
    {
        DB::table('gestiones')->insert(['fecha_gestion' => '2026-09-02', 'dni' => '12345678', 'dni_normalizado' => '12345678', 'telefono' => '900000001', 'status' => 'CONTACTO EFECTIVO', 'tipificacion' => 'PPC', 'observacion' => '09:10:00 Promesa', 'fecha_pago' => '2026-09-02', 'monto_pago' => 100, 'nombre' => 'Asesor Uno']);
        DB::table('Asignacion_Expertis_Historico')->insert(['FEC_PRO' => '202609', 'Documento_RUC' => '12345678', 'documento_normalizado' => '12345678', 'NombreDeudor' => 'Cliente Uno', 'CARTERA' => 'A', 'cosecha' => 'COSECHA A', 'Canal' => 'ESCALL', 'conca' => '1-A']);
        DB::table('tipificaciones_expertis')->insert(['tipificacion' => 'PPC', 'gestion' => 'CEF', 'peso' => 1, 'activo' => true]);
    }
}
