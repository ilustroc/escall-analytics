<?php

namespace Tests\Feature;

use App\Jobs\SendExpertisGestionReportJob;
use App\Mail\ExpertisGestionReportMail;
use App\Models\AnalyticsAuditLog;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\GestionCsvExportService;
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
        DB::table('tipificaciones_expertis')->insert(['tipificacion' => 'PPC', 'gestion' => 'CEF', 'peso' => 1, 'activo' => true]);
    }

    private function seedGestion(): void
    {
        DB::table('gestiones')->insert(['fecha_gestion' => '2026-09-23', 'dni' => '12345678', 'dni_normalizado' => '12345678', 'telefono' => '900000001', 'status' => 'CONTACTO EFECTIVO', 'tipificacion' => 'PPC', 'observacion' => '09:10:00 Promesa', 'fecha_pago' => '2026-09-23', 'monto_pago' => 100, 'nombre' => 'Asesor Uno']);
        DB::table('Asignacion_Expertis_Historico')->insert(['FEC_PRO' => '202609', 'Documento_RUC' => '12345678', 'documento_normalizado' => '12345678', 'NombreDeudor' => 'Cliente Uno', 'CARTERA' => 'A', 'cosecha' => 'COSECHA A', 'Canal' => 'ESCALL', 'conca' => '1-A']);
    }
}
