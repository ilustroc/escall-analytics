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
    private const GESTION_EXPORT_SORTS = ['idGestion' => 'id_gestion', 'dni' => 'dni', 'asesor' => 'asesor', 'fechaLlamada' => 'fecha_llamada', 'nivel1' => 'nivel1', 'nivel2' => 'nivel2', 'monto' => 'monto'];

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
        foreach ($this->orderGestionExport($this->gestionExportSource($filters), $sort, $direction)->cursor()->chunk(500) as $chunk) {
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
    private function gestionExportSource(array $filters): Builder
    {
        $manual = $this->manualExportSource($filters);
        $discador = $this->discadorExportSource($filters);

        return DB::query()
            ->fromRaw('('.$manual->toSql().' UNION ALL '.$discador->toSql().') as gestion_export')
            ->mergeBindings($manual)
            ->mergeBindings($discador)
            ->select('gestion_export.*');
    }

    /** @param array<string, mixed> $filters */
    private function manualExportSource(array $filters): Builder
    {
        $query = $this->gestionSource($filters)->addSelect([
            DB::raw('NULL as nombre_cliente'),
            DB::raw('NULL as cartera'),
            DB::raw('NULL as cosecha'),
        ]);

        $this->applyMonthlyAssignmentRequirement(
            $query,
            $filters,
            'g.fecha_gestion',
            static fn (Builder $assignment): Builder => $assignment->whereColumn('a.documento_normalizado', 'g.dni_normalizado'),
        );

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function discadorExportSource(array $filters): Builder
    {
        $query = DB::table('llamadas as l')->selectRaw(<<<'SQL'
            l.id as id_gestion,
            'ESCALL' as canal_gestion,
            'ESCALL' as canal_asignacion,
            TRIM(l.documento) as dni,
            UPPER(COALESCE(NULLIF(TRIM(l.usuario), ''), 'MARCADOR')) as asesor,
            'KATHERINE HUAMAN' as equipo,
            l.telefono as telefono,
            DATE(l.fecha_gestion) as fecha_llamada,
            TIME(l.fecha_gestion) as hora,
            l.tipo_gestion as nivel1,
            l.resultado as nivel2,
            NULL as fecha_compromiso,
            0 as monto,
            l.observacion as observacion,
            'DISCADOR' as medio_gestion,
            l.cliente as nombre_cliente,
            NULL as cartera,
            NULL as cosecha
        SQL);

        $query->where('l.fecha_gestion', '>=', $filters['fechaDesde'])
            ->where('l.fecha_gestion', '<', CarbonImmutable::parse((string) $filters['fechaHasta'])->addDay()->toDateString());

        $this->applyMonthlyAssignmentRequirement(
            $query,
            $filters,
            'l.fecha_gestion',
            static fn (Builder $assignment): Builder => $assignment->whereColumn('a.documento_normalizado', DB::raw('TRIM(l.documento)')),
        );

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

    private function orderGestionExport(Builder $query, string $sort, string $direction): Builder
    {
        $column = self::GESTION_EXPORT_SORTS[$sort] ?? self::GESTION_EXPORT_SORTS['fechaLlamada'];

        return $query->orderBy($column, $direction)->orderByDesc('id_gestion');
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
        $this->enrichRowsWithMonthlyAssignments($rows);

        $weightKeys = $rows
            ->filter(static fn (object $row): bool => $row->medio_gestion === 'MANUAL')
            ->pluck('nivel2')
            ->unique()
            ->values()
            ->all();
        $weights = DB::table('tipificaciones_expertis')->whereIn('tipificacion', $weightKeys)->pluck('peso', 'tipificacion');

        return $rows->each(function (object $row) use ($weights): void {
            if ($row->medio_gestion === 'DISCADOR') {
                $row->puntaje = $this->discadorScore($row);

                return;
            }

            $row->puntaje = (int) ($weights[$row->nivel2] ?? 0);
        });
    }

    /** @param Collection<int, object> $rows */
    private function enrichRowsWithMonthlyAssignments(Collection $rows): void
    {
        $assignmentByPeriodAndDni = [];

        foreach ($rows->groupBy(fn (object $row): string => CarbonImmutable::parse((string) $row->fecha_llamada)->format('Ym')) as $period => $periodRows) {
            $dnis = $periodRows->pluck('dni')->filter(static fn (mixed $dni): bool => trim((string) $dni) !== '')->unique()->values()->all();
            if ($dnis === []) {
                continue;
            }

            $assignments = DB::table(config('analytics.assignment_table').' as a')
                ->select([
                    'a.documento_normalizado',
                    'a.NombreDeudor as nombre_cliente',
                    'a.CARTERA as cartera',
                    'a.cosecha',
                    'a.Canal as canal',
                ])
                ->where('a.FEC_PRO', $period)
                ->whereIn('a.documento_normalizado', $dnis)
                ->get()
                ->groupBy('documento_normalizado');

            foreach ($assignments as $dni => $matches) {
                $carteras = $matches->pluck('cartera')
                    ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
                    ->map(static fn (mixed $value): string => strtoupper(trim((string) $value)))
                    ->unique()
                    ->values();
                $cosechas = $matches->pluck('cosecha')
                    ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
                    ->map(static fn (mixed $value): string => trim((string) $value))
                    ->unique()
                    ->values();
                $canales = $matches->pluck('canal')
                    ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
                    ->map(static fn (mixed $value): string => strtoupper(trim((string) $value)))
                    ->unique()
                    ->values();

                $assignmentByPeriodAndDni[$period.'|'.$dni] = [
                    'nombre_cliente' => $matches->pluck('nombre_cliente')
                        ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
                        ->map(static fn (mixed $value): string => trim((string) $value))
                        ->first(),
                    'cartera' => $carteras->count() > 1 ? 'MULTICARTERA' : $carteras->first(),
                    'cosecha' => $cosechas->count() > 1 ? 'MULTICOSECHA' : $cosechas->first(),
                    'canal_asignacion' => $canales->count() > 1 ? 'MULTICANAL' : ($canales->first() ?? 'ESCALL'),
                ];
            }
        }

        $rows->each(function (object $row) use ($assignmentByPeriodAndDni): void {
            $key = CarbonImmutable::parse((string) $row->fecha_llamada)->format('Ym').'|'.$row->dni;
            $assignment = $assignmentByPeriodAndDni[$key] ?? null;
            $row->nombre_cliente = $assignment['nombre_cliente'] ?? null;
            $row->cartera = $assignment['cartera'] ?? null;
            $row->cosecha = $assignment['cosecha'] ?? null;
            $row->canal_asignacion = $assignment['canal_asignacion'] ?? null;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  callable(Builder): Builder  $dniConstraint
     */
    private function applyMonthlyAssignmentRequirement(Builder $query, array $filters, string $dateColumn, callable $dniConstraint): void
    {
        $query->where(function (Builder $periodQuery) use ($filters, $dateColumn, $dniConstraint): void {
            foreach ($this->monthSlices($filters) as $slice) {
                $periodQuery->orWhere(function (Builder $sliceQuery) use ($slice, $dateColumn, $dniConstraint): void {
                    $sliceQuery->where($dateColumn, '>=', $slice['from'])
                        ->where($dateColumn, '<', $slice['to'])
                        ->whereExists(function (Builder $assignment) use ($slice, $dniConstraint): void {
                            $assignment->selectRaw('1')
                                ->from(config('analytics.assignment_table').' as a')
                                ->where('a.FEC_PRO', $slice['period']);

                            $dniConstraint($assignment);
                        });
                });
            }
        });
    }

    private function discadorScore(object $row): ?int
    {
        return match (strtoupper(trim((string) $row->nivel2))) {
            'NO CONTESTA' => 15,
            'ABANDONO' => 16,
            'BUZON' => 17,
            'FALLADO' => 18,
            default => null,
        };
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

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{period: string, from: string, to: string}>
     */
    private function monthSlices(array $filters): array
    {
        $from = CarbonImmutable::parse((string) $filters['fechaDesde'])->startOfDay();
        $endExclusive = CarbonImmutable::parse((string) $filters['fechaHasta'])->addDay()->startOfDay();
        $month = $from->startOfMonth();
        $slices = [];

        while ($month < $endExclusive) {
            $nextMonth = $month->addMonth();
            $slices[] = [
                'period' => $month->format('Ym'),
                'from' => $from->greaterThan($month) ? $from->toDateString() : $month->toDateString(),
                'to' => $endExclusive->lessThan($nextMonth) ? $endExclusive->toDateString() : $nextMonth->toDateString(),
            ];
            $month = $nextMonth;
        }

        return $slices;
    }
}
