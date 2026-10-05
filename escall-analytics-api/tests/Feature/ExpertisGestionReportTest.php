<?php

namespace Tests\Feature;

use App\Jobs\SendExpertisGestionReportJob;
use App\Mail\ExpertisGestionReportMail;
use App\Models\AnalyticsAuditLog;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\GestionCsvExportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExpertisGestionReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('analytics.expertis_report.to', ['alejandro.romero@goexpertis.com', 'Gina.Georffino@goexpertis.com']);
        config()->set('analytics.expertis_report.cc', ['katherine.supervision@escallperu.com', 'cespinozag@escallperu.com']);
        DB::connection()->getPdo()->sqliteCreateFunction('regexp', static fn (string $pattern, ?string $value): int => preg_match('/'.$pattern.'/', $value ?? ''));
        DB::connection()->getPdo()->sqliteCreateFunction('left', static fn (?string $value, int $length): string => substr($value ?? '', 0, $length));
        $this->createSourceTables();
    }

    public function test_job_sends_the_shared_csv_with_dynamic_subject_and_audit_log(): void
    {
        $this->seedGestion();
        Mail::fake();

        (new SendExpertisGestionReportJob('CORTE_14', '2026-09-23'))->handle(app(GestionCsvExportService::class), app(AnalyticsAuditService::class));

        Mail::assertSent(ExpertisGestionReportMail::class, function (ExpertisGestionReportMail $mail): bool {
            $mail->assertTo('alejandro.romero@goexpertis.com');
            $mail->assertTo('Gina.Georffino@goexpertis.com');
            $mail->assertHasCc('katherine.supervision@escallperu.com');
            $mail->assertHasCc('cespinozag@escallperu.com');
            $mail->assertHasSubject('Reporte de Gestiones – Expertis | 01 al 23 de septiembre de 2026');
            $this->assertStringContainsString('gestiones manuales y llamadas realizadas mediante discador', $mail->render());

            return count($mail->attachments()) === 1;
        });
        $this->assertDatabaseHas('analytics_audit_logs', ['action' => 'expertis_gestion_report_sent']);
        $audit = AnalyticsAuditLog::query()->where('action', 'expertis_gestion_report_sent')->firstOrFail();
        $this->assertSame('CORTE_14', $audit->metadata['cut']);
        $this->assertSame('2026-09-01', $audit->metadata['fechaDesde']);
        $this->assertSame('2026-09-23', $audit->metadata['fechaHasta']);
        $this->assertSame(1, $audit->metadata['gestiones']);
        $this->assertSame('Reporte_Gestiones_Expertis_20260901_20260923.csv', $audit->metadata['archivo']);
        Storage::disk('local')->assertMissing('reports/expertis/Reporte_Gestiones_Expertis_20260901_20260923.csv');
    }

    public function test_same_date_and_cut_is_not_sent_twice_and_empty_report_is_sent(): void
    {
        Mail::fake();
        $job = new SendExpertisGestionReportJob('CORTE_20', '2026-09-23');
        $job->handle(app(GestionCsvExportService::class), app(AnalyticsAuditService::class));
        $job->handle(app(GestionCsvExportService::class), app(AnalyticsAuditService::class));

        Mail::assertSent(ExpertisGestionReportMail::class, 1);
        $audit = AnalyticsAuditLog::query()->where('action', 'expertis_gestion_report_sent')->firstOrFail();
        $this->assertSame(0, $audit->metadata['gestiones']);
    }

    public function test_dry_run_generates_the_report_without_sending_email(): void
    {
        $this->seedGestion();
        Mail::fake();

        $this->artisan('analytics:expertis-report-email', ['--dry-run' => true, '--cut' => 'CORTE_14', '--date' => '2026-09-23'])
            ->expectsOutputToContain('Dry-run completado')
            ->assertSuccessful();

        Mail::assertNothingSent();
        Storage::disk('local')->assertMissing('reports/expertis/Reporte_Gestiones_Expertis_20260901_20260923.csv');
    }

    public function test_report_consolidates_manual_and_discador_without_losing_or_multiplying_calls(): void
    {
        $this->seedGestion();
        DB::table('Asignacion_Expertis_Historico')->insert([
            ['FEC_PRO' => '202609', 'Documento_RUC' => '87654321', 'documento_normalizado' => '87654321', 'NombreDeudor' => 'Asignacion Septiembre', 'CARTERA' => 'OH', 'cosecha' => 'COSECHA A', 'Canal' => 'ESCALL', 'conca' => '2-A'],
            ['FEC_PRO' => '202609', 'Documento_RUC' => '87654321', 'documento_normalizado' => '87654321', 'NombreDeudor' => 'Asignacion Septiembre', 'CARTERA' => 'OH', 'cosecha' => 'COSECHA B', 'Canal' => 'ESCALL', 'conca' => '2-B'],
            ['FEC_PRO' => '202608', 'Documento_RUC' => '11111111', 'documento_normalizado' => '11111111', 'NombreDeudor' => 'Asignacion Antigua', 'CARTERA' => 'ANTERIOR', 'cosecha' => 'COSECHA ANTIGUA', 'Canal' => 'ESCALL', 'conca' => '3-A'],
        ]);
        DB::table('llamadas')->insert([
            ['id' => 10, 'fecha_gestion' => '2026-09-23 10:11:12', 'documento' => '87654321', 'cliente' => 'Cliente Discador', 'cartera' => 'EXPERTIS_OCTUBRE_2026', 'usuario' => '  ', 'telefono' => '900000010', 'resultado' => 'BUZON', 'tipo_gestion' => 'NO CONTACTO', 'observacion' => 'Buzon discador'],
            ['id' => 11, 'fecha_gestion' => '2026-09-24 11:12:13', 'documento' => '11111111', 'cliente' => 'Cliente Sin Asignacion', 'cartera' => 'MULTI_AGOSTO_2026', 'usuario' => 'ana asesora', 'telefono' => '900000011', 'resultado' => 'NO CONTESTA', 'tipo_gestion' => 'NO CONTACTO', 'observacion' => 'Sin asignacion del mes'],
            ['id' => 13, 'fecha_gestion' => '2026-09-25 12:13:14', 'documento' => '33333333', 'cliente' => 'Cliente Abandono', 'cartera' => 'OPERATIVA', 'usuario' => 'asesor', 'telefono' => '900000013', 'resultado' => 'ABANDONO', 'tipo_gestion' => 'NO CONTACTO', 'observacion' => 'Abandono'],
            ['id' => 14, 'fecha_gestion' => '2026-09-26 13:14:15', 'documento' => '44444444', 'cliente' => 'Cliente Fallado', 'cartera' => 'OPERATIVA', 'usuario' => 'asesor', 'telefono' => '900000014', 'resultado' => 'FALLADO', 'tipo_gestion' => 'NO CONTACTO', 'observacion' => 'Fallado'],
            ['id' => 12, 'fecha_gestion' => '2026-10-01 00:00:00', 'documento' => '22222222', 'cliente' => 'Fuera de rango', 'cartera' => 'FUERA', 'usuario' => 'asesor', 'telefono' => '900000012', 'resultado' => 'BUZON', 'tipo_gestion' => 'NO CONTACTO', 'observacion' => 'No debe salir'],
        ]);

        $report = app(GestionCsvExportService::class)->createPrivateReport(CarbonImmutable::parse('2026-09-30'));
        $contents = Storage::disk('local')->get($report['path']);
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $headers = str_getcsv(substr(array_shift($lines), 3), ';');
        $records = collect(array_filter($lines, static fn (string $line): bool => $line !== ''))
            ->map(fn (string $line): array => array_combine($headers, str_getcsv($line, ';')))
            ->keyBy('ID Gestion');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertCount(19, $headers);
        $this->assertSame(5, $report['rows']);
        $this->assertCount(5, $records);
        $this->assertSame('MANUAL', $records['1']['Medio Gestion']);
        $this->assertSame('Cliente Uno', $records['1']['Nombre Cliente']);
        $this->assertSame('DISCADOR', $records['10']['Medio Gestion']);
        $this->assertSame('NO CONTACTO', $records['10']['Nivel 1']);
        $this->assertSame('BUZON', $records['10']['Nivel 2']);
        $this->assertSame('17', $records['10']['Puntaje']);
        $this->assertSame('MARCADOR', $records['10']['Asesor']);
        $this->assertSame('Cliente Discador', $records['10']['Nombre Cliente']);
        $this->assertSame('OH', $records['10']['Cartera']);
        $this->assertSame('MULTICOSECHA', $records['10']['Cosecha']);
        $this->assertSame('NO CONTACTO', $records['11']['Nivel 1']);
        $this->assertSame('NO CONTESTA', $records['11']['Nivel 2']);
        $this->assertSame('', $records['11']['Cosecha']);
        $this->assertSame('', $records['11']['Cartera']);
        $this->assertSame('15', $records['11']['Puntaje']);
        $this->assertSame('NO CONTACTO', $records['13']['Nivel 1']);
        $this->assertSame('ABANDONO', $records['13']['Nivel 2']);
        $this->assertSame('16', $records['13']['Puntaje']);
        $this->assertSame('NO CONTACTO', $records['14']['Nivel 1']);
        $this->assertSame('FALLADO', $records['14']['Nivel 2']);
        $this->assertSame('18', $records['14']['Puntaje']);
        $this->assertArrayNotHasKey('12', $records);
    }

    public function test_send_command_only_enqueues_the_report_job(): void
    {
        Queue::fake();

        $this->artisan('analytics:expertis-report-email', ['--send' => true, '--cut' => 'CORTE_20', '--date' => '2026-09-23'])
            ->assertSuccessful();

        Queue::assertPushed(SendExpertisGestionReportJob::class, static fn (SendExpertisGestionReportJob $job): bool => $job->cut === 'CORTE_20' && $job->reportDate === '2026-09-23' && $job->queue === 'reports');
    }

    public function test_scheduler_registers_both_weekday_and_saturday_cuts(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('analytics:expertis-report-email --send --cut=CORTE_14', $output);
        $this->assertStringContainsString('analytics:expertis-report-email --send --cut=CORTE_20', $output);
        $this->assertStringContainsString('0 14 * * 1,2,3,4,5,6', $output);
        $this->assertStringContainsString('0 20 * * 1,2,3,4,5,6', $output);
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
        Schema::create('tipificaciones_expertis', static function (Blueprint $table): void {
            $table->string('tipificacion');
            $table->string('gestion');
            $table->unsignedInteger('peso');
            $table->boolean('activo')->default(true);
        });
        Schema::create('llamadas', static function (Blueprint $table): void {
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
        DB::table('tipificaciones_expertis')->insert(['tipificacion' => 'PPC', 'gestion' => 'CEF', 'peso' => 1, 'activo' => true]);
    }

    private function seedGestion(): void
    {
        DB::table('gestiones')->insert(['fecha_gestion' => '2026-09-23', 'dni' => '12345678', 'dni_normalizado' => '12345678', 'telefono' => '900000001', 'status' => 'CONTACTO EFECTIVO', 'tipificacion' => 'PPC', 'observacion' => '09:10:00 Promesa', 'fecha_pago' => '2026-09-23', 'monto_pago' => 100, 'nombre' => 'Asesor Uno']);
        DB::table('Asignacion_Expertis_Historico')->insert(['FEC_PRO' => '202609', 'Documento_RUC' => '12345678', 'documento_normalizado' => '12345678', 'NombreDeudor' => 'Cliente Uno', 'CARTERA' => 'A', 'cosecha' => 'COSECHA A', 'Canal' => 'ESCALL', 'conca' => '1-A']);
    }
}
