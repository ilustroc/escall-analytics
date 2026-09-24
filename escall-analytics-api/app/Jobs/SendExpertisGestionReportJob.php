<?php

namespace App\Jobs;

use App\Mail\ExpertisGestionReportMail;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\GestionCsvExportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendExpertisGestionReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 3;

    public function __construct(public readonly string $cut, public readonly string $reportDate)
    {
        $this->onQueue('reports');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('expertis-gestion-report:'.$this->reportDate.':'.$this->cut))->expireAfter(1800)->dontRelease()];
    }

    public function handle(GestionCsvExportService $csv, AnalyticsAuditService $audit): void
    {
        if ($audit->wasExpertisReportSent($this->reportDate, $this->cut)) {
            return;
        }

        $date = CarbonImmutable::parse($this->reportDate, (string) config('analytics.expertis_report.timezone'));
        $report = $csv->createPrivateReport($date);
        $to = config('analytics.expertis_report.to', []);
        $cc = config('analytics.expertis_report.cc', []);

        try {
            Mail::to($to)->cc($cc)->send(new ExpertisGestionReportMail(
                $report['subject'], $report['periodLabel'], $report['path'], $report['filename'],
            ));
            $audit->logSystem('expertis_gestion_report_sent', [
                'reportDate' => $this->reportDate,
                'cut' => $this->cut,
                'fechaDesde' => $report['filters']['fechaDesde'],
                'fechaHasta' => $report['filters']['fechaHasta'],
                'gestiones' => $report['rows'],
                'archivo' => $report['filename'],
                'to' => $to,
                'cc' => $cc,
            ]);
        } catch (Throwable $exception) {
            $audit->logSystem('expertis_gestion_report_failed', [
                'reportDate' => $this->reportDate,
                'cut' => $this->cut,
                'fechaDesde' => $report['filters']['fechaDesde'],
                'fechaHasta' => $report['filters']['fechaHasta'],
                'archivo' => $report['filename'],
                'error' => 'No se pudo enviar el reporte de gestiones Expertis.',
            ]);

            throw $exception;
        } finally {
            $csv->delete($report['path']);
        }
    }
}
