<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExpertisGestionesRequest;
use App\Http\Resources\ExpertisGestionResource;
use App\Services\Analytics\ExpertisQueryService;
use Illuminate\Http\JsonResponse;

final class ExpertisGestionController extends Controller
{
    public function __invoke(ExpertisGestionesRequest $request, ExpertisQueryService $queries): JsonResponse
    {
        $perPage = (int) $request->validated('perPage', 25);
        $page = (int) $request->validated('page', 1);
        $filters = $request->filters();
        $search = $request->validated('search');
        $rows = $queries->detail(
            $filters,
            $search,
            $request->validated('sortField', 'fechaLlamada'),
            $request->validated('sortDirection', 'desc'),
        )
            ->forPage($page, $perPage)
            ->get();
        $total = $queries->detailTotal($filters, $search);
        $lastPage = max(1, (int) ceil($total / $perPage));

        return response()->json([
            'data' => ExpertisGestionResource::collection($queries->enrichDetails($rows))->resolve(),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => $lastPage,
            ],
        ]);
    }
}
