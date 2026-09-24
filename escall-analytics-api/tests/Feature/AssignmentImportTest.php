<?php

namespace Tests\Feature;

use App\Jobs\ProcessExpertisAssignmentImport;
use App\Jobs\ValidateExpertisAssignmentImport;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\AssignmentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AssignmentImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryUploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->createAssignmentTable();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryUploads as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_provider_cannot_access_import_configuration_or_status(): void
    {
        $provider = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_PROVEEDOR]);
        $import = AnalyticsImport::factory()->create();
        $this->actingAs($provider, 'analytics');

        $this->getJson('/api/v1/imports/config')->assertForbidden();
        $this->getJson("/api/v1/imports/{$import->id}")->assertForbidden();
        $this->get('/api/v1/importaciones/expertis/asignacion/plantilla')->assertForbidden();
    }

    public function test_supervisor_can_download_the_csv_template(): void
    {
        $supervisor = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_SUPERVISOR]);
        $this->actingAs($supervisor, 'analytics')
            ->get('/api/v1/importaciones/expertis/asignacion/plantilla')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('Documento_RUC')
            ->assertSee('CUENTA-EJEMPLO-001');
    }

    public function test_supervisor_can_upload_a_csv_for_background_validation(): void
    {
        $supervisor = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_SUPERVISOR]);
        $response = $this->actingAs($supervisor, 'analytics')->post('/api/v1/importaciones/expertis/asignacion/validar', [
            'period' => '202609',
            'file' => $this->csv('asignacion.csv', [['10000001', 'EXPERTIS', 'CLIENTE UNO', 'CTA-001']]),
        ]);

        $response->assertAccepted();
        $import = AnalyticsImport::query()->findOrFail($response->json('id'));
        $this->assertContains($import->status, ['uploaded', 'validated']);
    }

    public function test_invalid_period_and_invalid_file_are_rejected(): void
    {
        $admin = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_ADMINISTRADOR]);
        $this->actingAs($admin, 'analytics')
            ->post('/api/v1/importaciones/expertis/asignacion/validar', ['period' => '2026-09', 'file' => UploadedFile::fake()->create('bad.exe', 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    }

    public function test_xlsx_and_xlsx_renamed_as_csv_are_rejected(): void
    {
        $admin = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_ADMINISTRADOR]);
        $this->actingAs($admin, 'analytics')
            ->post('/api/v1/importaciones/expertis/asignacion/validar', ['period' => '202609', 'file' => UploadedFile::fake()->create('asignacion.xlsx', 5)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Formato no permitido. Utiliza un archivo CSV o TXT.');

        $this->actingAs($admin, 'analytics')
            ->post('/api/v1/importaciones/expertis/asignacion/validar', ['period' => '202609', 'file' => $this->uploadedFile('renombrado.csv', "PK\x03\x04contenido-no-csv")])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Formato no permitido. Utiliza un archivo CSV o TXT.');
    }

    public function test_csv_and_txt_delimiters_and_windows_1252_are_streamed_to_staging(): void
    {
        foreach ([['asignacion.csv', ';'], ['asignacion.csv', ','], ['asignacion.txt', '|'], ['asignacion.txt', "\t"]] as [$filename, $delimiter]) {
            $import = $this->uploadAsSupervisor($this->csvContent([['10000001', 'EXPERTIS', 'GUTIÉRREZ', 'CTA-'.bin2hex(random_bytes(3))]], $delimiter), $filename);
            $this->runValidation($import);
            $import->refresh();
            $this->assertSame('validated', $import->status);
            $import->forceFill(['status' => 'cancelled'])->save();
        }

        $content = iconv('UTF-8', 'Windows-1252', $this->csvContent([['10000002', 'EXPERTIS', 'NIÑO GUTIÉRREZ', 'CTA-ANSI']], ';'));
        $import = $this->uploadAsSupervisor((string) $content, 'asignacion.txt');
        $this->runValidation($import);
        $this->assertDatabaseHas('analytics_expertis_assignment_staging', ['import_id' => $import->id, 'NombreDeudor' => 'NIÑO GUTIÉRREZ']);
    }

    public function test_empty_file_is_rejected(): void
    {
        $admin = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_ADMINISTRADOR]);
        $this->actingAs($admin, 'analytics')
            ->post('/api/v1/importaciones/expertis/asignacion/validar', ['period' => '202609', 'file' => $this->uploadedFile('vacio.csv', '')])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El archivo está vacío.');
    }

    public function test_one_hundred_thousand_rows_are_streamed_without_replacing_assignment(): void
    {
        config()->set('analytics.import_chunk_size', 2000);
        $import = $this->uploadAsSupervisor($this->largeCsv(100000), 'asignacion.csv');
        $this->runValidation($import);
        $import->refresh();

        $this->assertSame('validated', $import->status);
        $this->assertSame(100000, $import->rows_total);
        $this->assertSame(100000, $import->rows_processed);
        $this->assertSame(100000, $import->rows_inserted);
        $this->assertDatabaseCount('Asignacion_Expertis_Historico', 0);
    }

    public function test_headers_are_validated_by_the_background_job(): void
    {
        $import = $this->uploadAsSupervisor("Documento_RUC,CARTERA\n10000001,EXPERTIS\n");
        $this->runValidation($import);

        $import->refresh();
        $this->assertSame('failed', $import->status);
        $this->assertNotEmpty($import->metadata['validation']['errors']);
    }

    public function test_duplicate_accounts_fail_validation_without_touching_assignment(): void
    {
        $import = $this->uploadAsSupervisor($this->csvContent([
            ['10000001', 'EXPERTIS', 'CLIENTE UNO', 'CTA-001'],
            ['10000002', 'EXPERTIS', 'CLIENTE DOS', 'CTA-001'],
        ]));
        $this->runValidation($import);

        $import->refresh();
        $this->assertSame('failed', $import->status);
        $this->assertSame(1, $import->rows_rejected);
        $this->assertSame(1, $import->metadata['validation']['duplicates']);
        $this->assertDatabaseCount('Asignacion_Expertis_Historico', 0);
    }

    public function test_validated_staging_swaps_a_period_atomically(): void
    {
        DB::table('Asignacion_Expertis_Historico')->insert(['FEC_PRO' => '202609', 'Documento_RUC' => 'OLD', 'CARTERA' => 'OLD', 'NombreDeudor' => 'ANTERIOR', 'conca' => 'OLD-1']);
        $import = $this->uploadAsSupervisor($this->csvContent([
            ['10000001', 'EXPERTIS', 'CLIENTE UNO', 'CTA-001'],
            ['10000002', 'EXPERTIS', 'CLIENTE DOS', 'CTA-002'],
        ]));
        $this->runValidation($import);
        $import->refresh();
        $this->assertSame('validated', $import->status);

        $this->runImport($import);
        $import->refresh();
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->rows_inserted);
        $this->assertDatabaseCount('Asignacion_Expertis_Historico', 2);
        $this->assertDatabaseMissing('Asignacion_Expertis_Historico', ['conca' => 'OLD-1']);
    }

    public function test_completed_hash_cannot_be_uploaded_again_for_the_same_period(): void
    {
        $content = $this->csvContent([['10000001', 'EXPERTIS', 'CLIENTE UNO', 'CTA-001']]);
        $import = $this->uploadAsSupervisor($content);
        $this->runValidation($import);
        $this->runImport($import);

        $this->actingAs(AnalyticsUser::query()->findOrFail($import->created_by), 'analytics')
            ->post('/api/v1/importaciones/expertis/asignacion/validar', ['period' => '202609', 'file' => $this->uploadedFile('same.csv', $content)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este archivo ya fue importado correctamente para el periodo seleccionado.');
    }

    public function test_failed_swap_rolls_back_the_previous_period_data(): void
    {
        Schema::table('Asignacion_Expertis_Historico', static function ($table): void {
            $table->unique('Documento_RUC');
        });
        DB::table('Asignacion_Expertis_Historico')->insert([
            ['FEC_PRO' => '202609', 'Documento_RUC' => 'OLD', 'CARTERA' => 'OLD', 'NombreDeudor' => 'ANTERIOR', 'conca' => 'OLD-1'],
            ['FEC_PRO' => '202608', 'Documento_RUC' => 'DUP', 'CARTERA' => 'OLD', 'NombreDeudor' => 'OTRO', 'conca' => 'OLD-2'],
        ]);
        $import = $this->uploadAsSupervisor($this->csvContent([['DUP', 'EXPERTIS', 'CLIENTE UNO', 'CTA-001']]));
        $this->runValidation($import);
        $this->runImport($import);

        $this->assertDatabaseHas('Asignacion_Expertis_Historico', ['FEC_PRO' => '202609', 'conca' => 'OLD-1']);
        $import->refresh();
        $this->assertSame('failed', $import->status);
    }

    private function uploadAsSupervisor(string $content, string $filename = 'asignacion.csv'): AnalyticsImport
    {
        $user = AnalyticsUser::factory()->create(['role' => AnalyticsUser::ROLE_SUPERVISOR]);
        $response = $this->actingAs($user, 'analytics')->post('/api/v1/importaciones/expertis/asignacion/validar', [
            'period' => '202609',
            'file' => $this->uploadedFile($filename, $content),
        ]);

        $response->assertAccepted();

        return AnalyticsImport::query()->findOrFail($response->json('id'));
    }

    private function runValidation(AnalyticsImport $import): void
    {
        (new ValidateExpertisAssignmentImport($import->id))->handle(app(AssignmentImportService::class), app(AnalyticsAuditService::class));
    }

    private function runImport(AnalyticsImport $import): void
    {
        $import->forceFill(['status' => 'processing', 'started_at' => now()])->save();
        $job = new ProcessExpertisAssignmentImport($import->id);
        try {
            $job->handle(app(AssignmentImportService::class), app(AnalyticsCache::class), app(AnalyticsAuditService::class));
        } catch (\Throwable $exception) {
            $job->failed($exception);
        }
    }

    /** @param list<list<string>> $rows */
    private function csv(string $name, array $rows): UploadedFile
    {
        return $this->uploadedFile($name, $this->csvContent($rows));
    }

    private function uploadedFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'escall-import-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->temporaryUploads[] = $path;

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** @param list<list<string>> $rows */
    private function csvContent(array $rows, string $delimiter = ','): string
    {
        $content = "Documento_RUC{$delimiter}CARTERA{$delimiter}NombreDeudor{$delimiter}conca\n";
        foreach ($rows as $row) {
            $content .= implode($delimiter, $row)."\n";
        }

        return $content;
    }

    private function largeCsv(int $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, ['Documento_RUC', 'CARTERA', 'NombreDeudor', 'conca']);
        for ($row = 1; $row <= $rows; $row++) {
            fputcsv($handle, [str_pad((string) $row, 8, '0', STR_PAD_LEFT), 'EXPERTIS', "CLIENTE {$row}", "CTA-{$row}"]);
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private function createAssignmentTable(): void
    {
        Schema::create('Asignacion_Expertis_Historico', static function ($table): void {
            $table->bigInteger('IdDeudor')->nullable();
            $table->char('FEC_PRO', 6);
            foreach (['Documento_RUC', 'CARTERA', 'NombreDeudor', 'conca', 'cosecha', 'Canal', 'MOVIMIENTO CANAL', 'Grupo', 'CodigoGrupo', 'Segmento', 'Subsegmento', 'DK', 'DT', 'RANGO_DEUDA', 'antiguedad', 'fechaCastigo', 'C1', 'C2', 'C3', 'C6', 'C12', 'C24', 'nvl_Asesor', 'nvl_Supervisor', 'nvl_Jefe', 'nvl_Gerente', 'nvl_Socio', 'CEF_INICIO', 'Percentil', 'telef1cef', 'telef2cef', 'telef1prim', 'telef2prim', 'telef1yp', 'telef2yp', 'telef1admin', 'telef2admin', 'telef1search', 'telef2search'] as $column) {
                $table->string($column)->nullable();
            }
        });
    }
}
