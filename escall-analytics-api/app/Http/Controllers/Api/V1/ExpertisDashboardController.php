<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExpertisDashboardRequest;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\ExpertisDashboardService;
use Illuminate\Http\JsonResponse;

final class ExpertisDashboardController extends Controller
{
    public function __invoke(ExpertisDashboardRequest $request, ExpertisDashboardService $dashboard, AnalyticsCache $cache): JsonResponse
    {
        $filters = $request->filters();

        return response()->json($cache->remember(
            'expertis-dashboard',
            $filters,
            60,
            fn (): array => $dashboard->dashboard($filters),
        ));
    }
}
