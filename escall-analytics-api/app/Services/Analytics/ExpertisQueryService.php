<?php

namespace App\Services\Analytics;

use App\Support\Analytics\Tipificacion;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ExpertisQueryService
{
    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'fechaLlamada' => 'g.fecha_gestion',
        'hora' => 'hora',
        'dni' => 'g.dni_normalizado',
        'asesor' => 'g.nombre',
        'nivel1' => 'g.status',
        'nivel2' => 'g.tipificacion',
        'fechaCompromiso' => 'g.fecha_pago',
        'monto' => 'g.monto_pago',
        'medioGestion' => 'medio_gestion',
        'telefono' => 'g.telefono',
        'equipo' => 'equipo',
    ];

    /** @param array<string, mixed> $filters */
    public function filtered(array $filters): Builder
    {
        $query = DB::table('gestiones as g')->whereIn('g.tipificacion', Tipificacion::codes());

        $this->applyDateRange($query, $filters);
        $this->whereIn($query, DB::raw('UPPER(TRIM(g.nombre))'), $filters['asesor'] ?? []);
        $this->whereIn($query, 'g.status', $filters['nivel1'] ?? []);
        $this->whereIn($query, 'g.tipificacion', $filters['nivel2'] ?? []);
        $this->applyTeam($query, $filters['equipo'] ?? []);
        $this->applyCosecha($query, $filters);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    public function consolidated(array $filters, bool $byAdvisor = false): Builder
    {
        return $this->consolidatedSource($this->filtered($filters), $byAdvisor);
    }

    public function consolidatedSource(Builder $source, bool $byAdvisor = false): Builder
    {
        $columns = 'g.dni_normalizado as dni, g.status as nivel1, g.tipificacion as nivel2, g.fecha_gestion as fecha_llamada, g.id as id_gestion';

        if ($byAdvisor) {
            $columns .= ', UPPER(TRIM(g.nombre)) as asesor';
        }

        $filtered = (clone $source)
            ->whereNotNull('g.dni_normalizado')
            ->where('g.dni_normalizado', '<>', '')
            ->selectRaw($columns);

        $partition = $byAdvisor ? 'asesor, dni' : 'dni';
        $ranked = DB::query()
            ->fromSub($filtered, 'filtered')
            ->selectRaw(
                'dni, nivel1, nivel2'.($byAdvisor ? ', asesor' : '')
                .', '.Tipificacion::gestionExpression('nivel2').' as gestion'
                .', ROW_NUMBER() OVER (PARTITION BY '.$partition.' ORDER BY '
                .Tipificacion::weightExpression('nivel2').' asc, fecha_llamada desc, id_gestion desc) as row_number',
            );

        return DB::query()->fromSub($ranked, 'ranked')->where('row_number', 1);
    }

    /** @param array<string, mixed> $filters */
    public function detail(array $filters, ?string $search, string $sortField, string $sortDirection): Builder
    {
        $query = $this->detailSource($filters, $search);

        if ($sortField === 'nombreCliente') {
            return $query->orderByRaw($this->assignmentSortExpression('NombreDeudor').' '.$sortDirection);
        }

        if ($sortField === 'cosecha') {
            return $query->orderByRaw($this->assignmentSortExpression('cosecha').' '.$sortDirection);
        }

        return $query->orderBy(self::SORT_COLUMNS[$sortField] ?? self::SORT_COLUMNS['fechaLlamada'], $sortDirection)->orderByDesc('g.id');
    }

    /** @param array<string, mixed> $filters */
    public function detailTotal(array $filters, ?string $search): int
    {
        return (int) $this->detailSource($filters, $search)->count();
    }

    /** @param Collection<int, object> $rows
     * @return Collection<int, object>
     */
    public function enrichDetails(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $assignments = collect();
        foreach ($rows->groupBy(fn (object $row): string => CarbonImmutable::parse((string) $row->fecha_llamada)->format('Ym')) as $period => $periodRows) {
            $dnis = $periodRows->pluck('dni')->filter()->unique()->values()->all();
            if ($dnis === []) {
                continue;
            }

            $assignments = $assignments->merge(DB::table(config('analytics.assignment_table').' as a')
                ->select([
                    'a.NombreDeudor as nombre_cliente',
                    'a.CARTERA as cartera',
                    'a.cosecha',
                    'a.Canal as canal',
                    'a.conca as cuenta',
                    'a.documento_normalizado',
                ])
                ->where('a.FEC_PRO', $period)
                ->whereIn('a.documento_normalizado', $dnis)
                ->get());
        }

        $assignmentByDni = $assignments->groupBy('documento_normalizado');

        return $rows->map(function (object $row) use ($assignmentByDni): object {
            $matches = $assignmentByDni->get($row->dni, collect());
            $carteras = $matches->pluck('cartera')->filter(fn (mixed $value): bool => trim((string) $value) !== '')->map(fn (mixed $value): string => strtoupper(trim((string) $value)))->unique()->values();
            $cosechas = $matches->pluck('cosecha')->filter(fn (mixed $value): bool => trim((string) $value) !== '')->map(fn (mixed $value): string => trim((string) $value))->unique()->values();
            $canales = $matches->pluck('canal')->filter(fn (mixed $value): bool => trim((string) $value) !== '')->map(fn (mixed $value): string => strtoupper(trim((string) $value)))->unique()->values();

            $row->nombre_cliente = $matches->pluck('nombre_cliente')->filter(fn (mixed $value): bool => trim((string) $value) !== '')->map(fn (mixed $value): string => trim((string) $value))->first();
            $row->cartera = $carteras->count() > 1 ? 'MULTICARTERA' : $carteras->first();
            $row->cosecha = $cosechas->count() > 1 ? 'MULTICOSECHA' : $cosechas->first();
            $row->canal_asignacion = $canales->count() > 1 ? 'MULTICANAL' : ($canales->first() ?? 'ESCALL');
            $row->cantidad_cuentas = $matches->pluck('cuenta')->filter(fn (mixed $value): bool => trim((string) $value) !== '')->unique()->count();

            return $row;
        });
    }

    /** @return list<string> */
    public function sortFields(): array
    {
        return array_merge(array_keys(self::SORT_COLUMNS), ['nombreCliente', 'cosecha']);
    }

    /** @param array<string, mixed> $filters */
    public function detailSource(array $filters, ?string $search): Builder
    {
        $query = $this->filtered($filters)->selectRaw(<<<'SQL'
            g.id as id_gestion,
            'ESCALL' as canal_gestion,
            'ESCALL' as canal_asignacion,
            g.dni_normalizado as dni,
            UPPER(TRIM(g.nombre)) as asesor,
            'KATHERINE HUAMAN' as equipo,
            g.telefono as telefono,
            g.fecha_gestion as fecha_llamada,
            CASE
                WHEN g.observacion REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' THEN SUBSTR(g.observacion, 12, 8)
                WHEN g.observacion REGEXP '^[0-9]{2}:[0-9]{2}:[0-9]{2}' THEN LEFT(g.observacion, 8)
                ELSE NULL
            END as hora,
            g.status as nivel1,
            g.tipificacion as nivel2,
            g.fecha_pago as fecha_compromiso,
            COALESCE(g.monto_pago, 0) as monto,
            CASE
                WHEN g.observacion REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' THEN TRIM(SUBSTR(g.observacion, 20))
                WHEN g.observacion REGEXP '^[0-9]{2}:[0-9]{2}:[0-9]{2}' THEN TRIM(SUBSTR(g.observacion, 9))
                ELSE g.observacion
            END as observacion,
            'MANUAL' as medio_gestion
        SQL);

        if ($search !== null && $search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $query) use ($term, $filters): void {
                $query->where('g.dni_normalizado', 'like', $term)
                    ->orWhere('g.telefono', 'like', $term)
                    ->orWhere('g.nombre', 'like', $term)
                    ->orWhere('g.observacion', 'like', $term);
                $this->applyAssignmentExists($query, $filters, function (Builder $assignment) use ($term): void {
                    $assignment->where('a.NombreDeudor', 'like', $term);
                }, true);
            });
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function applyDateRange(Builder $query, array $filters): void
    {
        $query->where('g.fecha_gestion', '>=', $filters['fechaDesde'])
            ->where('g.fecha_gestion', '<', CarbonImmutable::parse((string) $filters['fechaHasta'])->addDay()->toDateString());
    }

    /** @param array<string, mixed> $filters */
    private function applyCosecha(Builder $query, array $filters): void
    {
        $cosechas = $filters['cosecha'] ?? [];
        if (! is_array($cosechas) || $cosechas === []) {
            return;
        }

        $this->applyAssignmentExists($query, $filters, function (Builder $assignment) use ($cosechas): void {
            $assignment->whereIn('a.cosecha', $cosechas);
        });
    }

    /** @param list<string> $values */
    public function applyAssignmentFilter(Builder $query, array $filters, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->applyAssignmentExists($query, $filters, function (Builder $assignment) use ($column, $values): void {
            $assignment->whereIn('a.'.$column, $values);
        });
    }

    /** @param array<string, mixed> $filters */
    private function applyAssignmentExists(Builder $query, array $filters, ?Closure $constraint = null, bool $or = false): void
    {
        $method = $or ? 'orWhere' : 'where';
        $query->{$method}(function (Builder $periodQuery) use ($filters, $constraint): void {
            foreach ($this->monthSlices($filters) as $slice) {
                $periodQuery->orWhere(function (Builder $sliceQuery) use ($slice, $constraint): void {
                    $sliceQuery->where('g.fecha_gestion', '>=', $slice['from'])
                        ->where('g.fecha_gestion', '<', $slice['to'])
                        ->whereExists(function (Builder $assignment) use ($slice, $constraint): void {
                            $assignment->selectRaw('1')
                                ->from(config('analytics.assignment_table').' as a')
                                ->where('a.FEC_PRO', $slice['period'])
                                ->whereColumn('a.documento_normalizado', 'g.dni_normalizado');
                            if ($constraint !== null) {
                                $constraint($assignment);
                            }
                        });
                });
            }
        });
    }

    /** @param array<string, mixed> $filters
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

    private function applyTeam(Builder $query, mixed $teams): void
    {
        if (is_array($teams) && $teams !== [] && ! in_array((string) config('analytics.expertis_team'), $teams, true)) {
            $query->whereRaw('1 = 0');
        }
    }

    private function assignmentSortExpression(string $column): string
    {
        return sprintf('(SELECT MIN(a.`%s`) FROM `%s` a WHERE a.FEC_PRO = DATE_FORMAT(g.fecha_gestion, "%%Y%%m") AND a.documento_normalizado = g.dni_normalizado)', $column, config('analytics.assignment_table'));
    }

    private function whereIn(Builder $query, mixed $column, mixed $values): void
    {
        if (is_array($values) && $values !== []) {
            $query->whereIn($column, $values);
        }
    }
}
