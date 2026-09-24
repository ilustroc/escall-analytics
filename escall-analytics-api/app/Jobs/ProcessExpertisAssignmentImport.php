<?php

namespace App\Jobs;

use App\Models\AnalyticsImport;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\AssignmentImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessExpertisAssignmentImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(AssignmentImportService $imports, AnalyticsCache $cache, AnalyticsAuditService $audit): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        if ($import === null || ! in_array($import->status, ['validated', 'processing'], true)) {
            return;
        }

        $imports->process($import, $cache);
        $import->refresh();
        $audit->logImport('assignment_imported', $import, ['period' => $import->period, 'rows' => $import->rows_inserted]);
    }

    public function failed(Throwable $exception): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        if ($import !== null && $import->status !== 'completed') {
            app(AssignmentImportService::class)->fail($import, 'No se pudo completar la importación. La asignación anterior se mantuvo.', 'swap');
            app(AnalyticsAuditService::class)->logImport('assignment_import_failed', $import, ['stage' => 'swap']);
        }
    }
}
