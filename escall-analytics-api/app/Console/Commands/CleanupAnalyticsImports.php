<?php

namespace App\Console\Commands;

use App\Services\Analytics\AssignmentImportService;
use App\Services\Analytics\PaymentImportService;
use Illuminate\Console\Command;

final class CleanupAnalyticsImports extends Command
{
    protected $signature = 'analytics:imports:cleanup {--hours=48 : Antigüedad mínima de staging fallido en horas}';

    protected $description = 'Elimina filas de staging pertenecientes a importaciones fallidas o canceladas antiguas.';

    public function handle(AssignmentImportService $imports, PaymentImportService $payments): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = $imports->cleanup($hours) + $payments->cleanup($hours);
        $this->components->info("Filas de staging eliminadas: {$deleted}.");

        return self::SUCCESS;
    }
}
