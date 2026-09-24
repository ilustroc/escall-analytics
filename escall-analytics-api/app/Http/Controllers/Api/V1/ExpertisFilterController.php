<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsCache;
use App\Support\Analytics\Tipificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ExpertisFilterController extends Controller
{
    public function __invoke(AnalyticsCache $cache): JsonResponse
    {
        return response()->json($cache->remember('expertis-filters', [], 600, function (): array {
            $codes = Tipificacion::codes();
            $period = now((string) config('analytics.timezone'))->format('Ym');
            $asesores = DB::table('gestiones')->whereIn('tipificacion', $codes)
                ->selectRaw('UPPER(TRIM(nombre)) as asesor')->whereNotNull('nombre')->whereRaw("TRIM(nombre) <> ''")
                ->distinct()->orderBy('asesor')->pluck('asesor')->map(static fn (mixed $value): string => (string) $value)->all();
            $cosechas = DB::table(config('analytics.assignment_table'))->where('FEC_PRO', $period)
                ->whereNotNull('cosecha')->whereRaw("TRIM(cosecha) <> ''")->distinct()->orderBy('cosecha')
                ->pluck('cosecha')->map(static fn (mixed $value): string => (string) $value)->all();
            $nivel1 = DB::table('gestiones')->whereIn('tipificacion', $codes)
                ->whereNotNull('status')->whereRaw("TRIM(status) <> ''")->distinct()->orderBy('status')
                ->pluck('status')->map(static fn (mixed $value): string => (string) $value)->all();
            $nivel2 = DB::table('tipificaciones_expertis')->where('activo', true)->whereIn('tipificacion', $codes)
                ->orderBy('peso')->pluck('tipificacion')->map(static fn (mixed $value): string => strtoupper(trim((string) $value)))->all();

            return [
                'asesores' => $asesores,
                'equipos' => [(string) config('analytics.expertis_team')],
                'cosechas' => $cosechas,
                'nivel1' => $nivel1,
                'nivel2' => $nivel2,
            ];
        }));
    }
}
