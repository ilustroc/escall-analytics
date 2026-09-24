<?php

namespace App\Services\Analytics;

use App\Support\Analytics\Tipificacion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OperativoDashboardService
{
    public function __construct(
        private readonly ExpertisQueryService $queries,
        private readonly ExpertisDashboardService $expertis,
        private readonly PaymentQueryService $payments,
    ) {}

    /** @param array<string, mixed>
     * @return array<string, mixed>
     */
    public function dashboard(array $filters): array
    {
        $source = $this->queries->filtered($filters);
        $breakdown = $this->expertis->consolidatedBreakdown($source);
        $metrics = $this->expertis->metricsFromSource($source, $breakdown);
        $advisorStats = $this->advisorStats($source);
        $paymentSummary = $this->payments->summaryWithAgreement($filters)['summary'];

        return [
            'kpis' => [
                'gestiones' => $metrics['gestiones'],
                'clientesGestionados' => $metrics['clientesGestionados'],
                'clientesContactados' => $metrics['clientesContactados'],
                'contactoEfectivo' => $metrics['clientesContactoEfectivo'],
                'contactoEfectivoPorcentaje' => $metrics['contactoEfectivo'],
                'ppc' => (int) $breakdown->where('nivel2', 'PPC')->sum('clientes'),
                'montoComprometido' => $metrics['montoComprometido'],
                'clientesConPago' => $paymentSummary['clientes'],
                'montoPagado' => $paymentSummary['monto'],
            ],
            'rankingAsesores' => $this->advisorRanking($source, $advisorStats, $this->payments->byAdvisor($filters)),
            'actividadHoraria' => $this->hourlyActivity($source),
            'contactoEfectivoAsesor' => $this->effectiveByAdvisor($advisorStats),
            'ppcAsesor' => $this->ppcByAdvisor($advisorStats),
            'actividadReciente' => $this->recentActivity($source),
        ];
    }

    /** @return list<array<string, int|float|string>> */
    /** @param array<string,array{pagos:int,monto:float,clientes:int}> $payments */
    private function advisorRanking(Builder $source, Collection $clientes, array $payments): array
    {
        $gestiones = (clone $source)->whereNotNull('g.nombre')->where('g.nombre', '<>', '')
            ->selectRaw("UPPER(TRIM(g.nombre)) as asesor, COUNT(*) as gestiones, COUNT(DISTINCT NULLIF(g.dni_normalizado, '')) as clientes, SUM(CASE WHEN g.fecha_pago IS NOT NULL THEN COALESCE(g.monto_pago, 0) ELSE 0 END) as monto_promesa")
            ->groupByRaw('UPPER(TRIM(g.nombre))')->get()->keyBy('asesor');

        return $gestiones->map(function (object $gestion, string $asesor) use ($clientes, $payments): array {
            $cliente = $clientes->get($asesor);
            $totalClientes = (int) ($cliente->clientes ?? $gestion->clientes ?? 0);
            $efectivos = (int) ($cliente->efectivos ?? 0);

            return [
                'asesor' => $asesor,
                'gestiones' => (int) $gestion->gestiones,
                'clientes' => $totalClientes,
                'contactados' => (int) ($cliente->contactados ?? 0),
                'contactoEfectivo' => $efectivos,
                'contactoEfectivoPorcentaje' => $this->percentage($efectivos, $totalClientes),
                'ppc' => (int) ($cliente->ppc ?? 0),
                'montoPromesa' => round((float) ($gestion->monto_promesa ?? 0), 2),
                'pagos' => $payments[$asesor]['pagos'] ?? 0,
                'montoPago' => $payments[$asesor]['monto'] ?? 0,
                'intensidad' => $this->divide((int) $gestion->gestiones, $totalClientes),
            ];
        })->sortByDesc('gestiones')->values()->all();
    }

    /** @return list<array{hora: string, gestiones: int, contactados: int, contactoEfectivo: int, ppc: int}> */
    private function hourlyActivity(Builder $source): array
    {
        $hora = $this->hourExpression();
        $gestion = Tipificacion::gestionExpression('g.tipificacion');
        $rows = (clone $source)->whereRaw("HOUR({$hora}) between 8 and 18")
            ->selectRaw("HOUR({$hora}) as hora_numero, COUNT(*) as gestiones, SUM(CASE WHEN {$gestion} IN ('CEF', 'CNE') THEN 1 ELSE 0 END) as contactados, SUM(CASE WHEN {$gestion} = 'CEF' THEN 1 ELSE 0 END) as efectivos, SUM(CASE WHEN g.tipificacion = 'PPC' THEN 1 ELSE 0 END) as ppc")
            ->groupByRaw("HOUR({$hora})")->get()->keyBy('hora_numero');

        return array_map(static function (int $hour) use ($rows): array {
            $row = $rows->get($hour);

            return [
                'hora' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00',
                'gestiones' => (int) ($row->gestiones ?? 0),
                'contactados' => (int) ($row->contactados ?? 0),
                'contactoEfectivo' => (int) ($row->efectivos ?? 0),
                'ppc' => (int) ($row->ppc ?? 0),
            ];
        }, range(8, 18));
    }

    /** @return list<array{asesor: string, clientes: int, contactoEfectivo: int, porcentaje: float}> */
    private function effectiveByAdvisor(Collection $stats): array
    {
        return $stats->map(fn (object $row): array => [
            'asesor' => (string) $row->asesor,
            'clientes' => (int) $row->clientes,
            'contactoEfectivo' => (int) $row->efectivos,
            'porcentaje' => $this->percentage((int) $row->efectivos, (int) $row->clientes),
        ])->sortByDesc('porcentaje')->values()->all();
    }

    /** @return list<array{asesor: string, ppc: int}> */
    private function ppcByAdvisor(Collection $stats): array
    {
        return $stats->map(static fn (object $row): array => ['asesor' => (string) $row->asesor, 'ppc' => (int) $row->ppc])->sortByDesc('ppc')->values()->all();
    }

    /** @return Collection<string, object{asesor: string, clientes: int, contactados: int, efectivos: int, ppc: int}> */
    private function advisorStats(Builder $source): Collection
    {
        return DB::query()->fromSub($this->queries->consolidatedSource($source, true), 'clientes')
            ->selectRaw("asesor, COUNT(*) as clientes, SUM(CASE WHEN gestion IN ('CEF', 'CNE') THEN 1 ELSE 0 END) as contactados, SUM(CASE WHEN gestion = 'CEF' THEN 1 ELSE 0 END) as efectivos, SUM(CASE WHEN nivel2 = 'PPC' THEN 1 ELSE 0 END) as ppc")
            ->groupBy('asesor')->get()->keyBy('asesor');
    }

    /** @return list<array{hora: ?string, asesor: string, dni: string, nivel1: string, nivel2: string, monto: float}> */
    private function recentActivity(Builder $source): array
    {
        $hora = $this->hourExpression();

        return (clone $source)->selectRaw("{$hora} as hora, UPPER(TRIM(g.nombre)) as asesor, g.dni_normalizado as dni, g.status as nivel1, g.tipificacion as nivel2, COALESCE(g.monto_pago, 0) as monto")
            ->orderByDesc('g.fecha_gestion')->orderByDesc('hora')->orderByDesc('g.id')->limit(15)->get()
            ->map(static fn (object $row): array => [
                'hora' => $row->hora === null ? null : (string) $row->hora,
                'asesor' => (string) $row->asesor,
                'dni' => (string) $row->dni,
                'nivel1' => (string) $row->nivel1,
                'nivel2' => (string) $row->nivel2,
                'monto' => (float) $row->monto,
            ])->all();
    }

    private function hourExpression(): string
    {
        return "CASE WHEN g.observacion REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' THEN SUBSTR(g.observacion, 12, 8) WHEN g.observacion REGEXP '^[0-9]{2}:[0-9]{2}:[0-9]{2}' THEN LEFT(g.observacion, 8) ELSE NULL END";
    }

    private function divide(int|float $numerator, int|float $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : 0.0;
    }

    private function percentage(int|float $numerator, int|float $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0.0;
    }
}
