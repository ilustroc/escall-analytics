<?php

namespace App\Console\Commands;

use App\Jobs\SendExpertisGestionReportJob;
use App\Services\Analytics\GestionCsvExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:expertis-report-email {--dry-run : Genera el reporte sin enviar correo} {--send : Encola el envío real} {--cut=CORTE_14 : CORTE_14 o CORTE_20} {--date= : Fecha de corte YYYY-MM-DD}')]
#[Description('Genera o encola el Reporte de Gestiones Expertis')]
final class SendExpertisGestionReportEmail extends Command
{
    public function handle(GestionCsvExportService $csv): int
    {
        $cut = strtoupper((string) $this->option('cut'));
        if (! in_array($cut, ['CORTE_14', 'CORTE_20'], true)) {
            $this->error('El corte debe ser CORTE_14 o CORTE_20.');

            return self::FAILURE;
        }

        if ($this->option('send') && $this->option('dry-run')) {
            $this->error('Usa --dry-run o --send, no ambos.');

            return self::FAILURE;
        }

        try {
            $date = $this->option('date') === null
                ? CarbonImmutable::now((string) config('analytics.expertis_report.timezone'))->startOfDay()
                : CarbonImmutable::createFromFormat('Y-m-d', (string) $this->option('date'), (string) config('analytics.expertis_report.timezone'))->startOfDay();
        } catch (\Throwable) {
            $this->error('La fecha debe tener el formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($this->option('send')) {
            SendExpertisGestionReportJob::dispatch($cut, $date->toDateString());
            $this->info("Reporte {$cut} encolado para {$date->toDateString()}.");

            return self::SUCCESS;
        }

        $report = $csv->createPrivateReport($date);
        try {
            $this->table(['Campo', 'Valor'], [
                ['Destinatarios', implode(', ', config('analytics.expertis_report.to', []))],
                ['CC', implode(', ', config('analytics.expertis_report.cc', []))],
                ['Asunto', $report['subject']],
                ['Archivo', $report['filename']],
                ['Gestiones', $report['rows']],
            ]);
            $this->info('Dry-run completado. No se envió ningún correo.');
        } finally {
            $csv->delete($report['path']);
        }

        return self::SUCCESS;
    }
}
