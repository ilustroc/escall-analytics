<?php

namespace App\Jobs;

use App\Models\AnalyticsImport;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AssignmentImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ValidateExpertisAssignmentImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(AssignmentImportService $imports, AnalyticsAuditService $audit): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        // A worker may stop after the import is marked as validating.
        // Validation clears only this import's staging rows before reading it
        // again, so retrying that interrupted state is safe.
        if ($import === null || ! in_array($import->status, ['uploaded', 'validating'], true)) {
            return;
        }

        $imports->validate($import);
        $import->refresh();
        $audit->logImport(
            $import->status === 'validated' ? 'assignment_import_validated' : 'assignment_import_failed',
            $import,
            ['stage' => 'validation'],
        );
    }

    public function failed(Throwable $exception): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        if ($import !== null && ! in_array($import->status, ['completed', 'failed'], true)) {
            app(AssignmentImportService::class)->fail($import, 'No se pudo validar el archivo de asignación.', 'validation');
            app(AnalyticsAuditService::class)->logImport('assignment_import_failed', $import, ['stage' => 'validation']);
        }
    }
}
