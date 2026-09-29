<?php

namespace Tests\Feature;

use App\Jobs\ProcessExpertisPaymentImport;
use App\Jobs\ValidateExpertisPaymentImport;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\PaymentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PaymentImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryUploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->createPaymentTables();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryUploads as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_all_valid_payments_are_imported_after_background_processing(): void
    {
        $this->assignment('202609', 'CTA-001', '10000001');
        $import = $this->upload($this->csvContent([
            ['2026-09-02', 'CTA-001', '125.50', 'EJECUTIVO UNO', 'PPC'],
        ]));

        $this->runValidation($import);
        $this->runImport($import);
        $import->refresh();

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->rows_total);
        $this->assertSame(1, $import->rows_inserted);
        $this->assertSame(0, $import->rows_rejected);
        $this->assertDatabaseHas('pagos_expertis', ['cuenta_normalizada' => 'CTA-001', 'dni' => '10000001']);
    }

    public function test_valid_and_invalid_rows_are_imported_partially_and_error_csv_can_be_downloaded(): void
    {
        $this->assignment('202609', 'CTA-001', '10000001');
        $import = $this->upload($this->csvContent([
            ['2026-09-02', 'CTA-001', '125.50', 'EJECUTIVO UNO', 'PPC'],
            ['fecha-invalida', 'CTA-001', '0', '', 'OTRO'],
        ]));

        $this->runValidation($import);
        $this->runImport($import);
        $import->refresh();

        $this->assertSame('completed_with_errors', $import->status);
        $this->assertSame(1, $import->rows_inserted);
        $this->assertSame(1, $import->rows_rejected);
        $this->assertDatabaseCount('pagos_expertis', 1);
        $response = $this->actingAs(AnalyticsUser::query()->findOrFail($import->created_by), 'analytics')
            ->get("/api/v1/imports/{$import->id}/errores");
        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('FILA_ORIGEN', $response->streamedContent());
        self::assertStringContainsString('FECHA_PAGO', $response->streamedContent());
    }

    public function test_all_invalid_rows_finish_with_errors_without_inserting_payments(): void
    {
        $import = $this->upload($this->csvContent([
            ['2026-09-02', 'CTA-INEXISTENTE', '100.00', 'EJECUTIVO UNO', 'PPC'],
            ['2026-09-02', '', 'no-numero', '', 'XX'],
        ]));

        $this->runValidation($import);
        $import->refresh();

        $this->assertSame('completed_with_errors', $import->status);
        $this->assertSame(0, $import->rows_inserted);
        $this->assertSame(2, $import->rows_rejected);
        $this->assertDatabaseCount('pagos_expertis', 0);
    }

    public function test_invalid_structure_fails_the_entire_payment_import(): void
    {
        $import = $this->upload("FECHA_PAGO;CODIGO;MONTO\n2026-09-02;CTA-001;100.00\n");

        $this->runValidation($import);
        $import->refresh();

        $this->assertSame('failed', $import->status);
        $this->assertSame(0, $import->rows_total);
        $this->assertDatabaseCount('pagos_expertis', 0);
    }

    public function test_duplicate_payments_are_rejected_without_blocking_other_rows(): void
    {
        $this->assignment('202609', 'CTA-001', '10000001');
        $import = $this->upload($this->csvContent([
            ['2026-09-02', 'CTA-001', '100.00', 'EJECUTIVO UNO', 'PPC'],
            ['2026-09-02', 'CTA-001', '100.00', 'EJECUTIVO UNO', 'PPC'],
        ]));

        $this->runValidation($import);
        $this->runImport($import);
        $import->refresh();

        $this->assertSame('completed_with_errors', $import->status);
        $this->assertSame(1, $import->rows_inserted);
        $this->assertSame(1, $import->rows_rejected);
        $this->assertSame(1, $import->metadata['validation']['duplicates']);
        $this->assertDatabaseCount('pagos_expertis', 1);
    }

    public function test_validation_job_queues_payment_processing_without_user_confirmation(): void
    {
        $this->assignment('202609', 'CTA-001', '10000001');
        $import = $this->upload($this->csvContent([
            ['2026-09-02', 'CTA-001', '100.00', 'EJECUTIVO UNO', 'PPC'],
        ]));

        $this->runValidation($import);

        Queue::assertPushed(ProcessExpertisPaymentImport::class, fn (ProcessExpertisPaymentImport $job): bool => $job->importId === $import->id);
    }

    private function upload(string $content): AnalyticsImport
    {
        $user = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_SUPERVISOR]);
        $response = $this->actingAs($user, 'analytics')->post('/api/v1/importaciones/expertis/pagos/validar', [
            'file' => $this->uploadedFile('pagos.csv', $content),
        ]);
        $response->assertAccepted();

        return AnalyticsImport::query()->findOrFail($response->json('id'));
    }

    private function runValidation(AnalyticsImport $import): void
    {
        (new ValidateExpertisPaymentImport($import->id))->handle(app(PaymentImportService::class), app(AnalyticsAuditService::class));
    }

    private function runImport(AnalyticsImport $import): void
    {
        $import->forceFill(['status' => 'processing', 'started_at' => now()])->save();
        (new ProcessExpertisPaymentImport($import->id))->handle(app(PaymentImportService::class), app(AnalyticsCache::class), app(AnalyticsAuditService::class));
    }

    /** @param list<list<string>> $rows */
    private function csvContent(array $rows): string
    {
        $content = "FECHA_PAGO;CODIGO;MONTO;EJECUTIVO;TIPO_ACUERDO\n";
        foreach ($rows as $row) {
            $content .= implode(';', $row)."\n";
        }

        return $content;
    }

    private function uploadedFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'escall-payment-import-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->temporaryUploads[] = $path;

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function assignment(string $period, string $code, string $dni): void
    {
        DB::table('Asignacion_Expertis_Historico')->insert(['FEC_PRO' => $period, 'conca' => $code, 'Documento_RUC' => $dni]);
    }

    private function createPaymentTables(): void
    {
        Schema::create('Asignacion_Expertis_Historico', static function ($table): void {
            $table->char('FEC_PRO', 6);
            $table->string('conca');
            $table->string('Documento_RUC')->nullable();
        });
        Schema::create('pagos_expertis', static function ($table): void {
            $table->id();
            $table->date('fecha');
            $table->string('cuenta');
            $table->string('cuenta_normalizada');
            $table->string('dni')->nullable();
            $table->decimal('monto', 14, 2);
            $table->string('ejecutivo');
            $table->string('tipo_acuerdo', 3);
            $table->string('hash_fila', 64)->nullable();
            $table->unsignedInteger('numero_fila_origen')->nullable();
            $table->timestamps();
        });
    }
}
