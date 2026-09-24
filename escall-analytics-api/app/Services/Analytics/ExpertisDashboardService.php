<?php

namespace App\Services\Analytics;

use App\Support\Analytics\Tipificacion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ExpertisDashboardService
{
    public function __construct(private readonly ExpertisQueryService $queries, private readonly PaymentQueryService $payments) {}

    /** @param array<string, mixed>
     * @return array<string, mixed>
     */
    public function dashboard(array $filters): array
    {
        $source = $this->queries->filtered($filters);
        $breakdown = $this->consolidatedBreakdown($source);

        $metrics = $this->metricsFromSource($source, $breakdown);
        $paymentData = $this->payments->summaryWithAgreement($filters);
        $paymentSummary = $paymentData['summary'];

        return [
            'kpis' => $metrics,
            'pagos' => [...$paymentSummary, 'cumplimiento' => $metrics['montoComprometido'] > 0 ? round(($paymentSummary['monto'] / $metrics['montoComprometido']) * 100, 2) : 0],
            'pagosEvolucion' => $this->payments->evolution($filters),
            'pagosPorTipo' => $paymentData['byAgreement'],
            'rankingPagos' => $this->payments->ranking($filters),
            'evolucionDiaria' => $this->dailyEvolution($source),
            'nivel1' => $this->nivel1($breakdown),
        ];
    }

    /** @return array<string, int|float> */
    public function metricsFromSource(Builder $source, ?Collection $breakdown = null): array
    {
        $summary = (clone $source)->selectRaw("COUNT(*) as gestiones, COUNT(DISTINCT NULLIF(g.dni_normalizado, '')) as clientes_gestionados, COUNT(DISTINCT CASE WHEN g.fecha_pago IS NOT NULL THEN NULLIF(g.dni_normalizado, '') END) as clientes_compromiso, COALESCE(SUM(CASE WHEN g.fecha_pago IS NOT NULL THEN COALESCE(g.monto_pago, 0) ELSE 0 END), 0) as monto_comprometido")->first();
        $breakdown ??= $this->consolidatedBreakdown($source);
        $clientesContactados = (int) $breakdown->whereIn('gestion', ['CEF', 'CNE'])->sum('clientes');
        $clientesContactoEfectivo = (int) $breakdown->where('gestion', 'CEF')->sum('clientes');
        $gestiones = (int) ($summary->gestiones ?? 0);
        $clientesGestionados = (int) ($summary->clientes_gestionados ?? 0);

        return [
            'gestiones' => $gestiones,
            'clientesGestionados' => $clientesGestionados,
            'clientesContactados' => $clientesContactados,
            'contactabilidad' => $this->percentage($clientesContactados, $clientesGestionados),
            'clientesContactoEfectivo' => $clientesContactoEfectivo,
            'contactoEfectivo' => $this->percentage($clientesContactoEfectivo, $clientesGestionados),
            'intensidad' => $this->divide($gestiones, $clientesGestionados),
            'clientesCompromiso' => (int) ($summary->clientes_compromiso ?? 0),
            'montoComprometido' => round((float) ($summary->monto_comprometido ?? 0), 2),
        ];
    }

    /** @return list<array{fecha: string, gestiones: int, clientes: int}> */
    private function dailyEvolution(Builder $source): array
    {
        return (clone $source)->selectRaw("g.fecha_gestion as fecha, COUNT(*) as gestiones, COUNT(DISTINCT NULLIF(g.dni_normalizado, '')) as clientes")
            ->groupBy('g.fecha_gestion')->orderBy('g.fecha_gestion')->get()
            ->map(static fn (object $row): array => ['fecha' => (string) $row->fecha, 'gestiones' => (int) $row->gestiones, 'clientes' => (int) $row->clientes])->all();
    }

    /** @return list<array{nivel: string, clientes: int}> */
    private function nivel1(Collection $breakdown): array
    {
        $rows = $breakdown->groupBy('gestion')->map->sum('clientes');

        return array_map(static fn (string $gestion): array => ['nivel' => Tipificacion::nivel1($gestion), 'clientes' => (int) ($rows[$gestion] ?? 0)], ['CEF', 'CNE', 'NOC']);
    }

    /** @return Collection<int, object{gestion: string, nivel2: string, clientes: int}> */
    public function consolidatedBreakdown(Builder $source): Collection
    {
        return DB::query()->fromSub($this->queries->consolidatedSource($source), 'clientes')->selectRaw('gestion, nivel2, COUNT(*) as clientes')->groupBy('gestion', 'nivel2')->get();
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
