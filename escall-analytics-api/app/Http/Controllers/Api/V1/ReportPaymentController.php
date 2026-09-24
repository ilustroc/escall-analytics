<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportPaymentsRequest;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\ReportQueryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportPaymentController extends Controller
{
    public function index(ReportPaymentsRequest $request, ReportQueryService $reports): JsonResponse
    {
        $page = (int) $request->validated('page', 1);
        $perPage = (int) $request->validated('perPage', 25);
        $result = $reports->paymentPage($request->reportFilters(), $page, $perPage, $request->validated('sortField', 'fechaPago'), $request->validated('sortDirection', 'desc'));

        return response()->json([
            'data' => $result['rows']->map(static fn (object $row): array => ['id' => (int) $row->id, 'fechaPago' => (string) $row->fecha_pago, 'codigo' => (string) $row->codigo, 'dni' => $row->dni === null ? null : (string) $row->dni, 'monto' => (float) $row->monto, 'ejecutivo' => $row->ejecutivo === null ? null : (string) $row->ejecutivo, 'tipoAcuerdo' => $row->tipo_acuerdo === null ? null : (string) $row->tipo_acuerdo])->all(),
            'summary' => $result['summary'],
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $result['total'], 'lastPage' => max(1, (int) ceil($result['total'] / $perPage))],
        ]);
    }

    public function filters(ReportPaymentsRequest $request, ReportQueryService $reports, AnalyticsCache $cache): JsonResponse
    {
        $filters = $request->reportFilters();

        return response()->json($cache->remember('report-pagos-filters', [
            'fechaDesde' => $filters['fechaDesde'],
            'fechaHasta' => $filters['fechaHasta'],
        ], 600, fn (): array => $reports->paymentFilters($filters)));
    }

    public function export(ReportPaymentsRequest $request, ReportQueryService $reports, AnalyticsAuditService $audit): StreamedResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        $filters = $request->reportFilters();
        $sort = $request->validated('sortField', 'fechaPago');
        $direction = $request->validated('sortDirection', 'desc');
        $audit->log($request, 'report_pagos_exported', $actor, metadata: ['fechaDesde' => $filters['fechaDesde'], 'fechaHasta' => $filters['fechaHasta']]);

        return response()->streamDownload(function () use ($reports, $filters, $sort, $direction): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['FECHA_PAGO', 'CODIGO', 'DNI', 'MONTO', 'EJECUTIVO', 'TIPO_ACUERDO'], ';');
            foreach ($reports->paymentExport($filters, $sort, $direction) as $row) {
                fputcsv($output, [$row->fecha_pago, $row->codigo, $row->dni, $row->monto, $row->ejecutivo, $row->tipo_acuerdo], ';');
            }
            fclose($output);
        }, 'reporte-pagos.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
