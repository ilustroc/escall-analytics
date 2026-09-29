<?php

namespace App\Jobs;

use App\Models\AnalyticsImport;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\PaymentImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ProcessExpertisPaymentImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('imports');
    }

    public function handle(PaymentImportService $imports, AnalyticsCache $cache, AnalyticsAuditService $audit): void
    {
        $lock = Cache::lock('analytics-import-processing:payment:'.$this->importId, $this->timeout);
        if (! $lock->get()) {
            return;
        }
        try {
            $import = AnalyticsImport::query()->find($this->importId);
            if ($import === null || ! in_array($import->status, ['validated', 'processing'], true)) {
                return;
            }
            $imports->process($import, $cache);
            $import->refresh();
            $audit->logImport('payments_import_completed', $import, ['rows' => $import->rows_inserted]);
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        $import = AnalyticsImport::query()->find($this->importId);
        if ($import !== null && ! in_array($import->status, ['completed', 'completed_with_errors'], true)) {
            app(PaymentImportService::class)->fail($import, 'import');
            app(AnalyticsAuditService::class)->logImport('payments_import_failed', $import, ['stage' => 'import']);
        }
    }
}
