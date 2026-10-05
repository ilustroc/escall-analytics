<?php

namespace Tests\Feature;

use App\Models\AnalyticsUser;
use App\Services\Analytics\ExpertisDashboardService;
use App\Services\Analytics\ExpertisQueryService;
use App\Services\Analytics\PaymentQueryService;
use App\Services\Analytics\ReportQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DirectExpertisQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction('regexp', static fn (string $pattern, ?string $value): int => preg_match('/'.$pattern.'/', $value ?? ''));
        DB::connection()->getPdo()->sqliteCreateFunction('left', static fn (?string $value, int $length): string => substr($value ?? '', 0, $length));
    }

    public function test_dashboard_uses_direct_gestiones_and_consolidates_the_best_tipification(): void
    {
        $this->createSourceTables();
        $this->seedSourceRows();

        $dashboard = app(ExpertisDashboardService::class)->dashboard($this->filters());

        $this->assertSame(3, $dashboard['kpis']['gestiones']);
        $this->assertSame(2, $dashboard['kpis']['clientesGestionados']);
        $this->assertSame(2, $dashboard['kpis']['clientesContactados']);
        $this->assertSame(1, $dashboard['kpis']['clientesContactoEfectivo']);
        $this->assertSame(100.0, $dashboard['kpis']['contactabilidad']);
        $this->assertSame(50.0, $dashboard['kpis']['contactoEfectivo']);
        $this->assertSame(2, $dashboard['kpis']['clientesCompromiso']);
        $this->assertSame(300.0, $dashboard['kpis']['montoComprometido']);
    }

    public function test_detail_is_paginated_before_assignment_enrichment_and_cosecha_uses_exists(): void
    {
        $this->createSourceTables();
        $this->seedSourceRows();
        $queries = app(ExpertisQueryService::class);

        $rows = $queries->detail($this->filters(), null, 'fechaLlamada', 'asc')->limit(1)->get();
        $enriched = $queries->enrichDetails($rows);

        $this->assertCount(1, $enriched);
        $this->assertSame('MULTICARTERA', $enriched->first()->cartera);
        $this->assertSame('MULTICOSECHA', $enriched->first()->cosecha);
        $this->assertSame(2, $enriched->first()->cantidad_cuentas);
        $this->assertSame(3, $queries->detailTotal([...$this->filters(), 'cosecha' => ['COSECHA A']], null));
    }

    public function test_payment_summary_is_direct_and_does_not_change_management_metrics(): void
    {
        $this->createSourceTables();
        $this->seedSourceRows();
        DB::table('pagos_expertis')->insert([
            ['fecha' => '2026-09-02', 'cuenta_normalizada' => '1-A', 'dni' => '12345678', 'monto' => 125.50, 'ejecutivo' => 'ASESOR UNO', 'tipo_acuerdo' => 'PPC'],
            ['fecha' => '2026-09-02', 'cuenta_normalizada' => '2-B', 'dni' => '12345678', 'monto' => 50, 'ejecutivo' => 'ASESOR UNO', 'tipo_acuerdo' => 'PPM'],
        ]);

        $summary = app(PaymentQueryService::class)->summaryWithAgreement($this->filters())['summary'];
        $dashboard = app(ExpertisDashboardService::class)->dashboard($this->filters());

        $this->assertSame(1, $summary['clientes']);
        $this->assertSame(2, $summary['cuentas']);
        $this->assertSame(175.5, $summary['monto']);
        $this->assertSame(3, $dashboard['kpis']['gestiones']);
        $this->assertSame(175.5, $dashboard['pagos']['monto']);
        $this->assertSame([
            ['tipo' => 'PPC', 'cantidad' => 1, 'monto' => 125.5],
            ['tipo' => 'PPM', 'cantidad' => 1, 'monto' => 50.0],
            ['tipo' => 'PAR', 'cantidad' => 0, 'monto' => 0.0],
        ], $dashboard['pagosPorTipo']);
        $this->assertSame([['asesor' => 'ASESOR UNO', 'pagos' => 2, 'monto' => 175.5]], $dashboard['rankingPagos']);
    }

    public function test_reports_paginate_direct_sources_enrich_assignments_and_protect_provider_access(): void
    {
        $this->createSourceTables();
        $this->seedSourceRows();
        DB::table('tipificaciones_expertis')->insert([
            ['tipificacion' => 'PPC', 'gestion' => 'CEF', 'peso' => 1, 'activo' => true],
            ['tipificacion' => 'FAL', 'gestion' => 'CNE', 'peso' => 10, 'activo' => true],
            ['tipificacion' => 'NOC', 'gestion' => 'NOC', 'peso' => 16, 'activo' => true],
        ]);
        DB::table('pagos_expertis')->insert(['fecha' => '2026-09-02', 'cuenta_normalizada' => '1-A', 'dni' => '12345678', 'monto' => 125.50, 'ejecutivo' => 'ASESOR UNO', 'tipo_acuerdo' => 'PPC']);

        $filters = [...$this->filters(), 'cartera' => [], 'dni' => '', 'telefono' => '', 'search' => ''];
        $report = app(ReportQueryService::class)->gestionPage($filters, 1, 25, 'fechaLlamada', 'desc');

        $this->assertSame(3, $report['total']);
        $this->assertSame(2, $report['summary']['clientes']);
        $this->assertSame(1, $report['rows']->firstWhere('nivel2', 'PPC')->puntaje);
        $this->assertSame('MULTICARTERA', $report['rows']->firstWhere('dni', '12345678')->cartera);

        $supervisor = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_SUPERVISOR]);
        $this->actingAs($supervisor, 'analytics');
        $this->getJson('/api/v1/reportes/gestiones?fechaDesde=2026-09-01&fechaHasta=2026-09-14&perPage=25')
            ->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonPath('data.1.puntaje', 1);
        $this->getJson('/api/v1/reportes/gestiones?fechaDesde=2026-09-01&fechaHasta=2026-09-14&perPage=50&cartera[]=A')
            ->assertOk()->assertJsonPath('pagination.perPage', 50)->assertJsonPath('pagination.total', 3);
        $this->getJson('/api/v1/reportes/gestiones?fechaDesde=2026-09-01&fechaHasta=2026-09-14&perPage=100&nivel2[]=PPC')
            ->assertOk()->assertJsonPath('pagination.perPage', 100)->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/v1/reportes/pagos?fechaDesde=2026-09-01&fechaHasta=2026-09-14&ejecutivo=ASESOR%20UNO')
            ->assertOk()->assertJsonPath('pagination.total', 1);
        $gestionExport = $this->get('/api/v1/reportes/gestiones/exportar?fechaDesde=2026-09-01&fechaHasta=2026-09-14');
        $gestionExport->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('"ID Gestion";"Canal Gestion"', $gestionExport->streamedContent());

        $paymentExport = $this->get('/api/v1/reportes/pagos/exportar?fechaDesde=2026-09-01&fechaHasta=2026-09-14');
        $paymentExport->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('FECHA_PAGO;CODIGO', $paymentExport->streamedContent());

    }

    public function test_provider_cannot_access_reports(): void
    {
        $this->actingAs(AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_PROVEEDOR]), 'analytics');

        $this->getJson('/api/v1/reportes/gestiones')->assertForbidden();
        $this->getJson('/api/v1/reportes/pagos')->assertForbidden();
    }

    private function createSourceTables(): void
    {
        Schema::create('gestiones', function (Blueprint $table): void {
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
        Schema::create('Asignacion_Expertis_Historico', function (Blueprint $table): void {
            $table->string('FEC_PRO', 6);
            $table->string('Documento_RUC');
            $table->string('documento_normalizado');
            $table->string('NombreDeudor')->nullable();
            $table->string('CARTERA')->nullable();
            $table->string('cosecha')->nullable();
            $table->string('Canal')->nullable();
            $table->string('conca')->nullable();
        });
        Schema::create('pagos_expertis', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha');
            $table->string('cuenta_normalizada');
            $table->string('dni')->nullable();
            $table->decimal('monto', 14, 2);
            $table->string('ejecutivo')->nullable();
            $table->string('tipo_acuerdo')->nullable();
        });
        Schema::create('tipificaciones_expertis', function (Blueprint $table): void {
            $table->string('tipificacion');
            $table->string('gestion');
            $table->unsignedInteger('peso');
            $table->boolean('activo')->default(true);
        });
        Schema::create('llamadas', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('fecha_gestion');
            $table->string('documento')->nullable();
            $table->string('cliente')->nullable();
            $table->string('cartera')->nullable();
            $table->string('usuario')->nullable();
            $table->string('telefono')->nullable();
            $table->string('resultado')->nullable();
            $table->string('tipo_gestion')->nullable();
            $table->text('observacion')->nullable();
        });
    }

    private function seedSourceRows(): void
    {
        DB::table('gestiones')->insert([
            ['id' => 1, 'fecha_gestion' => '2026-09-01', 'dni' => '12345678', 'dni_normalizado' => '12345678', 'telefono' => '900000001', 'status' => 'NO CONTACTO', 'tipificacion' => 'NOC', 'observacion' => '08:10:00 Sin contacto', 'fecha_pago' => null, 'monto_pago' => null, 'nombre' => 'Asesor Uno'],
            ['id' => 2, 'fecha_gestion' => '2026-09-02', 'dni' => '12345678', 'dni_normalizado' => '12345678', 'telefono' => '900000001', 'status' => 'CONTACTO EFECTIVO', 'tipificacion' => 'PPC', 'observacion' => '09:10:00 Promesa', 'fecha_pago' => '2026-09-02', 'monto_pago' => 100, 'nombre' => 'Asesor Uno'],
            ['id' => 3, 'fecha_gestion' => '2026-09-02', 'dni' => '123', 'dni_normalizado' => '00000123', 'telefono' => '900000002', 'status' => 'CONTACTO NO EFECTIVO', 'tipificacion' => 'FAL', 'observacion' => '10:10:00 Llamada', 'fecha_pago' => '2026-09-02', 'monto_pago' => 200, 'nombre' => 'Asesor Dos'],
        ]);
        DB::table('Asignacion_Expertis_Historico')->insert([
            ['FEC_PRO' => '202609', 'Documento_RUC' => '12345678', 'documento_normalizado' => '12345678', 'NombreDeudor' => 'Cliente Uno', 'CARTERA' => 'A', 'cosecha' => 'COSECHA A', 'Canal' => 'ESCALL', 'conca' => '1-A'],
            ['FEC_PRO' => '202609', 'Documento_RUC' => '12345678', 'documento_normalizado' => '12345678', 'NombreDeudor' => 'Cliente Uno', 'CARTERA' => 'B', 'cosecha' => 'COSECHA B', 'Canal' => 'ESCALL', 'conca' => '2-B'],
            ['FEC_PRO' => '202609', 'Documento_RUC' => '00000123', 'documento_normalizado' => '00000123', 'NombreDeudor' => 'Cliente Dos', 'CARTERA' => 'A', 'cosecha' => 'COSECHA A', 'Canal' => 'ESCALL', 'conca' => '3-A'],
        ]);
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return ['fechaDesde' => '2026-09-01', 'fechaHasta' => '2026-09-14', 'asesor' => [], 'cosecha' => [], 'nivel1' => [], 'nivel2' => [], 'equipo' => []];
    }
}
