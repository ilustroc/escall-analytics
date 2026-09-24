<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportAssignmentRequest;
use App\Http\Requests\Api\V1\ValidateAssignmentImportRequest;
use App\Jobs\ProcessExpertisAssignmentImport;
use App\Jobs\ValidateExpertisAssignmentImport;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\AssignmentImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ExpertisAssignmentImportController extends Controller
{
    public function configuration(Request $request, AssignmentImportService $imports): JsonResponse
    {
        $validated = $request->validate(['period' => ['nullable', 'string', 'regex:/^\d{6}$/']]);
        $period = $validated['period'] ?? now()->format('Ym');

        return response()->json([
            ...$imports->configuration(),
            'period' => $period,
            'existingRows' => $imports->existingRows($period),
        ]);
    }

    public function validateFile(ValidateAssignmentImportRequest $request, AssignmentImportService $imports, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();

        try {
            $import = $imports->createUpload($request->file('file'), (string) $request->validated('period'), $actor);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        ValidateExpertisAssignmentImport::dispatch($import->id)->onQueue('imports');
        $audit->log($request, 'assignment_import_uploaded', $actor, AnalyticsImport::class, $import->id, ['period' => $import->period]);

        return response()->json($this->resource($import, $imports), 202);
    }

    public function import(ImportAssignmentRequest $request, AssignmentImportService $imports, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        $import = AnalyticsImport::query()->findOrFail((int) $request->validated('importId'));
        if ($import->created_by !== $actor->id) {
            return response()->json(['message' => 'No tienes permisos para ejecutar esta importación.'], 403);
        }

        $updated = AnalyticsImport::query()->whereKey($import->id)->where('status', 'validated')->update([
            'status' => 'processing',
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            return response()->json(['message' => 'La importación no se encuentra lista para ejecutarse.'], 422);
        }

        $import->refresh();
        ProcessExpertisAssignmentImport::dispatch($import->id)->onQueue('imports');
        $audit->log($request, 'assignment_import_processing', $actor, AnalyticsImport::class, $import->id, ['period' => $import->period]);

        return response()->json($this->resource($import, $imports), 202);
    }

    public function show(AnalyticsImport $import): JsonResponse
    {
        return response()->json($this->statusResource($import));
    }

    public function template(AssignmentImportService $imports): Response
    {
        return response($imports->templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-asignacion-expertis.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function history(Request $request, AssignmentImportService $imports): JsonResponse
    {
        $validated = $request->validate(['type' => ['nullable', 'in:expertis_asignacion,expertis_pagos']]);
        $records = AnalyticsImport::query()->with('creator:id,name')->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))->latest('id')->limit(100)->get()
            ->map(fn (AnalyticsImport $import): array => $this->resource($import, $imports, false));

        return response()->json(['data' => $records]);
    }

    /** @return array<string, mixed> */
    private function resource(AnalyticsImport $import, AssignmentImportService $imports, bool $includeExistingRows = true): array
    {
        $metadata = (array) $import->metadata;
        $validation = (array) ($metadata['validation'] ?? []);
        $duration = $import->started_at?->diffInSeconds($import->finished_at ?? now());

        return [
            'id' => $import->id,
            'type' => $import->type,
            'period' => $import->period,
            'originalFilename' => $import->original_filename,
            'fileSize' => (int) $import->file_size,
            'fileSha256' => $import->file_sha256,
            'status' => $import->status,
            'progress' => (int) $import->progress,
            'rowsTotal' => (int) $import->rows_total,
            'rowsProcessed' => (int) $import->rows_processed,
            'rowsInserted' => (int) $import->rows_inserted,
            'rowsRejected' => (int) $import->rows_rejected,
            'clients' => (int) ($validation['clients'] ?? 0),
            'accounts' => (int) ($validation['accounts'] ?? 0),
            'duplicates' => (int) ($validation['duplicates'] ?? 0),
            'errors' => array_slice((array) ($validation['errors'] ?? []), 0, 100),
            'error' => $import->error_summary,
            'existingRows' => $includeExistingRows && $import->type === AssignmentImportService::TYPE ? $imports->existingRows($import->period) : null,
            'createdBy' => $import->creator?->name,
            'createdAt' => $import->created_at?->toIso8601String(),
            'startedAt' => $import->started_at?->toIso8601String(),
            'finishedAt' => $import->finished_at?->toIso8601String(),
            'durationSeconds' => $duration,
            'metrics' => (array) ($metadata['metrics'] ?? []),
            'validAmount' => (float) ($validation['validAmount'] ?? 0),
            'byAgreement' => (array) ($validation['byAgreement'] ?? []),
        ];
    }

    /** @return array<string, int|string|null> */
    private function statusResource(AnalyticsImport $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status,
            'progress' => (int) $import->progress,
            'rowsTotal' => $import->rows_total > 0 ? (int) $import->rows_total : null,
            'rowsProcessed' => (int) $import->rows_processed,
            'rowsInserted' => (int) $import->rows_inserted,
            'rowsRejected' => (int) $import->rows_rejected,
            'startedAt' => $import->started_at?->toIso8601String(),
            'finishedAt' => $import->finished_at?->toIso8601String(),
            'error' => $import->error_summary,
        ];
    }
}
