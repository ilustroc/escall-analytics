<?php

namespace App\Console\Commands;

use App\Jobs\SendExpertisClientReportJob;
use App\Services\Analytics\ExpertisClientXlsxExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class SendExpertisClientReportEmail extends Command
{
    protected $signature = 'analytics:expertis-client-report-email {--dry-run : Genera el reporte sin enviar correo} {--send : Encola el envío real} {--force : Permite reenviar una fecha ya enviada} {--date= : Fecha YYYY-MM-DD}';

    protected $description = 'Genera o encola el Reporte de Cartera Expertis';

    public function handle(ExpertisClientXlsxExportService $xlsx): int
    {
        if ($this->option('send') && $this->option('dry-run')) {
            $this->error('Usa --dry-run o --send, no ambos.');

            return self::FAILURE;
        } if ($this->option('force') && ! $this->option('send')) {
            $this->error('Usa --force únicamente junto con --send.');

            return self::FAILURE;
        } try {
            $date = $this->option('date') === null ? CarbonImmutable::now((string) config('analytics.expertis_report.timezone'))->startOfDay() : CarbonImmutable::createFromFormat('Y-m-d', (string) $this->option('date'), (string) config('analytics.expertis_report.timezone'))->startOfDay();
        } catch (\Throwable) {
            $this->error('La fecha debe tener el formato YYYY-MM-DD.');

            return self::FAILURE;
        } if ($this->option('send')) {
            SendExpertisClientReportJob::dispatch($date->toDateString(), (bool) $this->option('force'));
            $this->info("Reporte de cartera encolado para {$date->toDateString()}.");

            return self::SUCCESS;
        } $report = $xlsx->createPrivateReport($date);
        try {
            $this->table(['Campo', 'Valor'], [['Fecha', $date->toDateString()], ['Archivo', $report['filename']], ['Registros', $report['rows']], ['Rango fuente', $report['range']], ['Destinatarios', implode(', ', config('analytics.expertis_report.to', []))], ['CC', implode(', ', config('analytics.expertis_report.cc', []))], ['Asunto', $report['subject']]]);
            $this->info('Dry-run completado. No se envió ningún correo.');
        } finally {
            $xlsx->delete($report['path']);
        }

return self::SUCCESS;
    }
}
