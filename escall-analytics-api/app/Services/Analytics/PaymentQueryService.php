<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Direct, index-friendly payment queries. Level filters deliberately do not affect payments. */
final class PaymentQueryService
{
    /** @param array<string,mixed> $filters */
    public function filtered(array $filters): Builder
    {
        $query = DB::table('pagos_expertis as p')
            ->where('p.fecha', '>=', $filters['fechaDesde'])
            ->where('p.fecha', '<', CarbonImmutable::parse((string) $filters['fechaHasta'])->addDay()->toDateString());
        if (($filters['asesor'] ?? []) !== []) {
            $query->whereIn(DB::raw('UPPER(TRIM(p.ejecutivo))'), $filters['asesor']);
        }
        if (($filters['cosecha'] ?? []) !== []) {
            $this->applyCosecha($query, $filters['cosecha']);
        }

        return $query;
    }

    /** @param array<string,mixed> $filters
     * @return array{summary:array{clientes:int,cuentas:int,monto:float,cantidadPagos:int},byAgreement:list<array{tipo:string,cantidad:int,monto:float}>}
     */
    public function summaryWithAgreement(array $filters): array
    {
        $row = $this->filtered($filters)->selectRaw("COUNT(*) cantidad, COUNT(DISTINCT NULLIF(p.dni, '')) clientes, COUNT(DISTINCT NULLIF(p.cuenta_normalizada, '')) cuentas, COALESCE(SUM(p.monto), 0) monto, SUM(CASE WHEN p.tipo_acuerdo='PPC' THEN 1 ELSE 0 END) ppc_cantidad, COALESCE(SUM(CASE WHEN p.tipo_acuerdo='PPC' THEN p.monto ELSE 0 END),0) ppc_monto, SUM(CASE WHEN p.tipo_acuerdo='PPM' THEN 1 ELSE 0 END) ppm_cantidad, COALESCE(SUM(CASE WHEN p.tipo_acuerdo='PPM' THEN p.monto ELSE 0 END),0) ppm_monto, SUM(CASE WHEN p.tipo_acuerdo='PAR' THEN 1 ELSE 0 END) par_cantidad, COALESCE(SUM(CASE WHEN p.tipo_acuerdo='PAR' THEN p.monto ELSE 0 END),0) par_monto")->first();
        $byAgreement = array_map(static fn (string $type): array => ['tipo' => $type, 'cantidad' => (int) ($row->{strtolower($type).'_cantidad'} ?? 0), 'monto' => round((float) ($row->{strtolower($type).'_monto'} ?? 0), 2)], ['PPC', 'PPM', 'PAR']);

        return ['summary' => ['clientes' => (int) ($row->clientes ?? 0), 'cuentas' => (int) ($row->cuentas ?? 0), 'monto' => round((float) ($row->monto ?? 0), 2), 'cantidadPagos' => (int) ($row->cantidad ?? 0)], 'byAgreement' => $byAgreement];
    }

    /** @param array<string,mixed> $filters @return list<array{fecha:string,cantidad:int,clientes:int,monto:float}> */
    public function evolution(array $filters): array
    {
        return $this->filtered($filters)->selectRaw("p.fecha, COUNT(*) cantidad, COUNT(DISTINCT NULLIF(p.dni, '')) clientes, COALESCE(SUM(p.monto),0) monto")
            ->groupBy('p.fecha')->orderBy('p.fecha')->get()->map(static fn (object $row): array => ['fecha' => (string) $row->fecha, 'cantidad' => (int) $row->cantidad, 'clientes' => (int) $row->clientes, 'monto' => round((float) $row->monto, 2)])->all();
    }

    /** @param array<string,mixed> $filters @return array<string,array{pagos:int,monto:float,clientes:int}> */
    public function byAdvisor(array $filters): array
    {
        return $this->filtered($filters)->whereNotNull('p.ejecutivo')->where('p.ejecutivo', '<>', '')
            ->selectRaw("UPPER(TRIM(p.ejecutivo)) asesor, COUNT(*) pagos, COALESCE(SUM(p.monto),0) monto, COUNT(DISTINCT NULLIF(p.dni, '')) clientes")
            ->groupByRaw('UPPER(TRIM(p.ejecutivo))')->get()->mapWithKeys(static fn (object $row): array => [(string) $row->asesor => ['pagos' => (int) $row->pagos, 'monto' => round((float) $row->monto, 2), 'clientes' => (int) $row->clientes]])->all();
    }

    /** @param array<string,mixed> $filters
     * @return list<array{asesor:string,pagos:int,monto:float}>
     */
    public function ranking(array $filters): array
    {
        return $this->filtered($filters)->whereNotNull('p.ejecutivo')->where('p.ejecutivo', '<>', '')
            ->selectRaw('UPPER(TRIM(p.ejecutivo)) asesor, COUNT(*) pagos, COALESCE(SUM(p.monto), 0) monto')
            ->groupByRaw('UPPER(TRIM(p.ejecutivo))')->orderByDesc('monto')->orderByDesc('pagos')->limit(10)->get()
            ->map(static fn (object $row): array => ['asesor' => (string) $row->asesor, 'pagos' => (int) $row->pagos, 'monto' => round((float) $row->monto, 2)])->all();
    }

    /** @param list<string> $cosechas */
    private function applyCosecha(Builder $query, array $cosechas): void
    {
        $driver = DB::connection()->getDriverName();
        $period = $driver === 'sqlite' ? "strftime('%Y%m', p.fecha)" : 'DATE_FORMAT(p.fecha, "%Y%m")';
        $query->whereExists(function (Builder $assignment) use ($cosechas, $period): void {
            $assignment->selectRaw('1')->from(config('analytics.assignment_table').' as a')
                ->whereRaw("a.FEC_PRO = {$period}")->whereColumn('a.conca', 'p.cuenta_normalizada')->whereIn('a.cosecha', $cosechas);
        });
    }
}
