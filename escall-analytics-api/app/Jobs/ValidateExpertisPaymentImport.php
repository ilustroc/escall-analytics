<?php

namespace App\Jobs;

use App\Models\AnalyticsImport;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\PaymentImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ValidateExpertisPaymentImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(PaymentImportService $imports, AnalyticsAuditService $audit): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        if ($import === null || ! in_array($import->status, ['uploaded', 'validating'], true)) {
            return;
        }
        $imports->validate($import);
        $import->refresh();
        $audit->logImport($import->status === 'validated' ? 'payments_import_validated' : 'payments_import_failed', $import, ['stage' => 'validation']);
    }

    public function failed(Throwable $exception): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        if ($import !== null && ! in_array($import->status, ['completed', 'failed'], true)) {
            app(PaymentImportService::class)->fail($import, 'validation');
            app(AnalyticsAuditService::class)->logImport('payments_import_failed', $import, ['stage' => 'validation']);
        }
    }
}
