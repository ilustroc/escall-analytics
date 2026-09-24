<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OperativoDashboardRequest;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\OperativoDashboardService;
use Illuminate\Http\JsonResponse;

final class OperativoDashboardController extends Controller
{
    public function __invoke(OperativoDashboardRequest $request, OperativoDashboardService $dashboard, AnalyticsCache $cache): JsonResponse
    {
        $filters = $request->filters();

        return response()->json($cache->remember(
            'operativo-dashboard',
            $filters,
            60,
            fn (): array => $dashboard->dashboard($filters),
        ));
    }
}
