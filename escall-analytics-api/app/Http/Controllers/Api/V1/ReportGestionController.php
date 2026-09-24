<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportGestionesRequest;
use App\Http\Resources\ReportGestionResource;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\GestionCsvExportService;
use App\Services\Analytics\ReportQueryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportGestionController extends Controller
{
    public function index(ReportGestionesRequest $request, ReportQueryService $reports): JsonResponse
    {
        $page = (int) $request->validated('page', 1);
        $perPage = (int) $request->validated('perPage', 25);
        $result = $reports->gestionPage($request->reportFilters(), $page, $perPage, $request->validated('sortField', 'fechaLlamada'), $request->validated('sortDirection', 'desc'));

        return response()->json([
            'data' => ReportGestionResource::collection($result['rows'])->resolve(),
            'summary' => $result['summary'],
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $result['total'], 'lastPage' => max(1, (int) ceil($result['total'] / $perPage))],
        ]);
    }

    public function filters(ReportGestionesRequest $request, ReportQueryService $reports, AnalyticsCache $cache): JsonResponse
    {
        $filters = $request->reportFilters();

        return response()->json($cache->remember('report-gestiones-filters', [
            'fechaDesde' => $filters['fechaDesde'],
            'fechaHasta' => $filters['fechaHasta'],
        ], 600, fn (): array => $reports->gestionFilters($filters)));
    }

    public function export(ReportGestionesRequest $request, GestionCsvExportService $csv, AnalyticsAuditService $audit): StreamedResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        $filters = $request->reportFilters();
        $sort = $request->validated('sortField', 'fechaLlamada');
        $direction = $request->validated('sortDirection', 'desc');
        $audit->log($request, 'report_gestiones_exported', $actor, metadata: ['fechaDesde' => $filters['fechaDesde'], 'fechaHasta' => $filters['fechaHasta']]);

        return response()->streamDownload(function () use ($csv, $filters, $sort, $direction): void {
            $output = fopen('php://output', 'wb');
            $csv->write($output, $filters, $sort, $direction);
            fclose($output);
        }, 'reporte-gestiones.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
