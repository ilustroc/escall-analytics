<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ReportQueryService
{
    /** @var array<string, string> */
    private const GESTION_SORTS = ['idGestion' => 'g.id', 'dni' => 'g.dni_normalizado', 'asesor' => 'g.nombre', 'fechaLlamada' => 'g.fecha_gestion', 'nivel1' => 'g.status', 'nivel2' => 'g.tipificacion', 'monto' => 'g.monto_pago'];

    /** @var array<string, string> */
    private const PAYMENT_SORTS = ['fechaPago' => 'p.fecha', 'codigo' => 'p.cuenta_normalizada', 'dni' => 'p.dni', 'monto' => 'p.monto', 'ejecutivo' => 'p.ejecutivo', 'tipoAcuerdo' => 'p.tipo_acuerdo'];

    public function __construct(private readonly ExpertisQueryService $gestiones) {}

    /** @param array<string, mixed> $filters
     * @return array{rows: Collection<int, object>, total: int, summary: array{gestiones: int, clientes: int, monto: float}}
     */
    public function gestionPage(array $filters, int $page, int $perPage, string $sort, string $direction): array
    {
        $source = $this->gestionSource($filters);
        $summary = (clone $source)->select([])->selectRaw("COUNT(*) as gestiones, COUNT(DISTINCT NULLIF(g.dni_normalizado, '')) as clientes, COALESCE(SUM(CASE WHEN g.fecha_pago IS NOT NULL THEN COALESCE(g.monto_pago, 0) ELSE 0 END), 0) as monto")->first();
        $total = (int) (clone $source)->count();
        $rows = $this->enrichGestionRows($this->orderGestiones($source, $sort, $direction)->forPage($page, $perPage)->get());

        return ['rows' => $rows, 'total' => $total, 'summary' => ['gestiones' => (int) ($summary->gestiones ?? 0), 'clientes' => (int) ($summary->clientes ?? 0), 'monto' => round((float) ($summary->monto ?? 0), 2)]];
    }

    /** @param array<string, mixed> $filters
     * @return Generator<int, object>
     */
    public function gestionExport(array $filters, string $sort, string $direction): Generator
    {
        foreach ($this->orderGestiones($this->gestionSource($filters), $sort, $direction)->cursor()->chunk(500) as $chunk) {
            foreach ($this->enrichGestionRows(collect($chunk)) as $row) {
                yield $row;
            }
        }
    }

    /** @param array<string, mixed> $filters
     * @return array{rows: Collection<int, object>, total: int, summary: array{pagos: int, clientes: int, monto: float}}
     */
    public function paymentPage(array $filters, int $page, int $perPage, string $sort, string $direction): array
    {
        $source = $this->paymentSource($filters);
        $summary = (clone $source)->select([])->selectRaw("COUNT(*) as pagos, COUNT(DISTINCT NULLIF(p.dni, '')) as clientes, COALESCE(SUM(p.monto), 0) as monto")->first();
        $total = (int) (clone $source)->count();
        $rows = $this->orderPayments($source, $sort, $direction)->forPage($page, $perPage)->get();

        return ['rows' => $rows, 'total' => $total, 'summary' => ['pagos' => (int) ($summary->pagos ?? 0), 'clientes' => (int) ($summary->clientes ?? 0), 'monto' => round((float) ($summary->monto ?? 0), 2)]];
    }

    /** @param array<string, mixed> $filters
     * @return Generator<int, object>
     */
    public function paymentExport(array $filters, string $sort, string $direction): Generator
    {
        yield from $this->orderPayments($this->paymentSource($filters), $sort, $direction)->cursor();
    }

    /** @param array<string, mixed> $filters
     * @return array{asesores:list<string>, cosechas:list<string>, carteras:list<string>, nivel1:list<string>, nivel2:list<string>}
     */
    public function gestionFilters(array $filters): array
    {
        $periods = $this->periods($filters);
        $base = $this->gestiones->filtered($filters);

        return [
            'asesores' => (clone $base)->select([])->selectRaw('UPPER(TRIM(g.nombre)) as asesor')->whereNotNull('g.nombre')->whereRaw("TRIM(g.nombre) <> ''")->distinct()->orderBy('asesor')->pluck('asesor')->map(static fn (mixed $value): string => (string) $value)->all(),
            'cosechas' => DB::table(config('analytics.assignment_table'))->whereIn('FEC_PRO', $periods)->whereNotNull('cosecha')->whereRaw("TRIM(cosecha) <> ''")->distinct()->orderBy('cosecha')->pluck('cosecha')->map(static fn (mixed $value): string => (string) $value)->all(),
            'carteras' => DB::table(config('analytics.assignment_table'))->whereIn('FEC_PRO', $periods)->whereNotNull('CARTERA')->whereRaw("TRIM(CARTERA) <> ''")->selectRaw('UPPER(TRIM(CARTERA)) as cartera')->distinct()->orderBy('cartera')->pluck('cartera')->map(static fn (mixed $value): string => (string) $value)->all(),
            'nivel1' => (clone $base)->whereNotNull('g.status')->whereRaw("TRIM(g.status) <> ''")->distinct()->orderBy('g.status')->pluck('g.status')->map(static fn (mixed $value): string => (string) $value)->all(),
            'nivel2' => DB::table('tipificaciones_expertis')->where('activo', true)->orderBy('peso')->pluck('tipificacion')->map(static fn (mixed $value): string => strtoupper(trim((string) $value)))->all(),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array{ejecutivos:list<string>, acuerdos:list<string>}
     */
    public function paymentFilters(array $filters): array
    {
        $source = $this->paymentSource($filters);

        return ['ejecutivos' => (clone $source)->select([])->whereNotNull('p.ejecutivo')->whereRaw("TRIM(p.ejecutivo) <> ''")->selectRaw('UPPER(TRIM(p.ejecutivo)) as ejecutivo')->distinct()->orderBy('ejecutivo')->pluck('ejecutivo')->map(static fn (mixed $value): string => (string) $value)->all(), 'acuerdos' => ['PAR', 'PPC', 'PPM']];
    }

    /** @param array<string, mixed> $filters */
    private function gestionSource(array $filters): Builder
    {
        $query = $this->gestiones->detailSource($filters, $filters['search'] ?? null);
        if (($filters['dni'] ?? '') !== '') {
            $query->where('g.dni_normalizado', trim((string) $filters['dni']));
        }
        if (($filters['telefono'] ?? '') !== '') {
            $query->where('g.telefono', 'like', '%'.addcslashes((string) $filters['telefono'], '%_\\').'%');
        }
        $carteras = $filters['cartera'] ?? [];
        if (is_array($carteras) && $carteras !== []) {
            $this->gestiones->applyAssignmentFilter($query, $filters, 'CARTERA', $carteras);
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function paymentSource(array $filters): Builder
    {
        $query = DB::table('pagos_expertis as p')->select(['p.id', 'p.fecha as fecha_pago', 'p.cuenta_normalizada as codigo', 'p.dni', 'p.monto', 'p.ejecutivo', 'p.tipo_acuerdo'])
            ->where('p.fecha', '>=', $filters['fechaDesde'])->where('p.fecha', '<', CarbonImmutable::parse((string) $filters['fechaHasta'])->addDay()->toDateString());
        foreach (['codigo' => 'p.cuenta_normalizada', 'dni' => 'p.dni', 'ejecutivo' => 'p.ejecutivo'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                $query->where($column, 'like', '%'.addcslashes((string) $filters[$filter], '%_\\').'%');
            }
        }
        $agreements = $filters['tipoAcuerdo'] ?? [];
        if (is_array($agreements) && $agreements !== []) {
            $query->whereIn('p.tipo_acuerdo', $agreements);
        }
        if (($filters['search'] ?? '') !== '') {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(fn (Builder $search): Builder => $search->where('p.cuenta_normalizada', 'like', $term)->orWhere('p.dni', 'like', $term)->orWhere('p.ejecutivo', 'like', $term));
        }

        return $query;
    }

    private function orderGestiones(Builder $query, string $sort, string $direction): Builder
    {
        if ($sort === 'puntaje') {
            return $query->orderByRaw('(SELECT t.peso FROM tipificaciones_expertis t WHERE t.tipificacion = g.tipificacion LIMIT 1) '.$direction)->orderByDesc('g.id');
        }

        return $query->orderBy(self::GESTION_SORTS[$sort] ?? self::GESTION_SORTS['fechaLlamada'], $direction)->orderByDesc('g.id');
    }

    private function orderPayments(Builder $query, string $sort, string $direction): Builder
    {
        return $query->orderBy(self::PAYMENT_SORTS[$sort] ?? self::PAYMENT_SORTS['fechaPago'], $direction)->orderByDesc('p.id');
    }

    /** @param Collection<int, object> $rows
     * @return Collection<int, object>
     */
    private function enrichGestionRows(Collection $rows): Collection
    {
        $rows = $this->gestiones->enrichDetails($rows);
        $weights = DB::table('tipificaciones_expertis')->whereIn('tipificacion', $rows->pluck('nivel2')->unique()->values()->all())->pluck('peso', 'tipificacion');

        return $rows->each(static function (object $row) use ($weights): void {
            $row->puntaje = (int) ($weights[$row->nivel2] ?? 0);
        });
    }

    /** @param array<string, mixed> $filters
     * @return list<string>
     */
    private function periods(array $filters): array
    {
        $from = CarbonImmutable::parse((string) $filters['fechaDesde'])->startOfMonth();
        $end = CarbonImmutable::parse((string) $filters['fechaHasta'])->startOfMonth();
        $periods = [];
        while ($from <= $end) {
            $periods[] = $from->format('Ym');
            $from = $from->addMonth();
        }

        return $periods;
    }
}
