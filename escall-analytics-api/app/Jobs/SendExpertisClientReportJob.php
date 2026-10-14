<?php

namespace App\Jobs;

use App\Mail\ExpertisClientReportMail;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\ExpertisClientXlsxExportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendExpertisClientReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public function __construct(public readonly string $reportDate, public readonly bool $force = false)
    {
        $this->onQueue('reports');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('expertis-client-report:'.$this->reportDate))->expireAfter(3600)->dontRelease()];
    }

    public function handle(ExpertisClientXlsxExportService $xlsx, AnalyticsAuditService $audit): void
    {
        if (! $this->force && $audit->wasExpertisClientReportSent($this->reportDate)) {
            return;
        } $report = $xlsx->createPrivateReport(CarbonImmutable::parse($this->reportDate, (string) config('analytics.expertis_report.timezone')));
        $to = config('analytics.expertis_report.to', []);
        $cc = config('analytics.expertis_report.cc', []);
        try {
            Mail::to($to)->cc($cc)->send(new ExpertisClientReportMail($report['subject'], $report['periodLabel'], $report['path'], $report['filename']));
            $audit->logSystem('expertis_client_report_sent', ['reportDate' => $this->reportDate, 'rows' => $report['rows'], 'range' => $report['range'], 'archivo' => $report['filename'], 'to' => $to, 'cc' => $cc, ...($this->force ? ['forced' => true] : [])]);
        } catch (Throwable $exception) {
            $audit->logSystem('expertis_client_report_failed', ['reportDate' => $this->reportDate, 'error' => 'No se pudo enviar el reporte de cartera Expertis.']);
            throw $exception;
        } finally {
            $xlsx->delete($report['path']);
        }
    }
}
