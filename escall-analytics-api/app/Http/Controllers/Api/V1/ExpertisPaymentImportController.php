<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportPaymentRequest;
use App\Http\Requests\Api\V1\ValidatePaymentImportRequest;
use App\Jobs\ProcessExpertisPaymentImport;
use App\Jobs\ValidateExpertisPaymentImport;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\PaymentImportService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ExpertisPaymentImportController extends Controller
{
    public function configuration(PaymentImportService $imports): JsonResponse
    {
        return response()->json($imports->configuration());
    }

    public function validateFile(ValidatePaymentImportRequest $request, PaymentImportService $imports, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        try {
            $import = $imports->createUpload($request->file('file'), $actor);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        ValidateExpertisPaymentImport::dispatch($import->id)->onQueue('imports');
        $audit->log($request, 'payments_import_uploaded', $actor, AnalyticsImport::class, $import->id);

        return response()->json($this->resource($import), 202);
    }

    public function import(ImportPaymentRequest $request, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        $import = AnalyticsImport::query()->findOrFail((int) $request->validated('importId'));
        if ($import->type !== PaymentImportService::TYPE || $import->created_by !== $actor->id) {
            return response()->json(['message' => 'No tienes permisos para ejecutar esta importación.'], 403);
        }
        if (AnalyticsImport::query()->whereKey($import->id)->where('status', 'validated')->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]) !== 1) {
            return response()->json(['message' => 'La importación no se encuentra lista para ejecutarse.'], 422);
        }
        $import->refresh();
        ProcessExpertisPaymentImport::dispatch($import->id)->onQueue('imports');
        $audit->log($request, 'payments_import_processing', $actor, AnalyticsImport::class, $import->id);

        return response()->json($this->resource($import), 202);
    }

    public function template(PaymentImportService $imports): Response
    {
        return response($imports->templateCsv(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="plantilla-pagos-expertis.csv"', 'Cache-Control' => 'no-store']);
    }

    /** @return array<string,mixed> */
    private function resource(AnalyticsImport $import): array
    {
        $validation = (array) (($import->metadata ?? [])['validation'] ?? []);

        return [
            'id' => $import->id, 'type' => $import->type, 'period' => $import->period,
            'originalFilename' => $import->original_filename, 'fileSize' => (int) $import->file_size, 'fileSha256' => $import->file_sha256,
            'status' => $import->status, 'progress' => (int) $import->progress, 'rowsTotal' => $import->rows_total > 0 ? (int) $import->rows_total : null,
            'rowsProcessed' => (int) $import->rows_processed, 'rowsInserted' => (int) $import->rows_inserted, 'rowsRejected' => (int) $import->rows_rejected,
            'clients' => (int) ($validation['clients'] ?? 0), 'accounts' => (int) ($validation['accounts'] ?? 0), 'duplicates' => (int) ($validation['duplicates'] ?? 0),
            'validAmount' => (float) ($validation['validAmount'] ?? 0), 'byAgreement' => (array) ($validation['byAgreement'] ?? []), 'errors' => array_slice((array) ($validation['errors'] ?? []), 0, 100),
            'error' => $import->error_summary, 'createdAt' => $import->created_at?->toIso8601String(), 'startedAt' => $import->started_at?->toIso8601String(), 'finishedAt' => $import->finished_at?->toIso8601String(),
            'durationSeconds' => $import->started_at?->diffInSeconds($import->finished_at ?? now()), 'metrics' => (array) (($import->metadata ?? [])['metrics'] ?? []),
        ];
    }
}
