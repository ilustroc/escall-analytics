<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsAuditLog;
use App\Models\AnalyticsImport;
use App\Models\AnalyticsUser;
use Illuminate\Http\Request;

final class AnalyticsAuditService
{
    /** @param array<string, mixed>|null $metadata */
    public function log(Request $request, string $action, ?AnalyticsUser $user = null, ?string $entityType = null, ?int $entityId = null, ?array $metadata = null): void
    {
        AnalyticsAuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed>|null $metadata */
    public function logImport(string $action, AnalyticsImport $import, ?array $metadata = null): void
    {
        AnalyticsAuditLog::query()->create([
            'user_id' => $import->created_by,
            'action' => $action,
            'entity_type' => AnalyticsImport::class,
            'entity_id' => $import->id,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string,mixed>|null $metadata */
    public function logSystem(string $action, ?array $metadata = null): void
    {
        AnalyticsAuditLog::query()->create([
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }

    public function wasExpertisReportSent(string $date, string $cut): bool
    {
        return AnalyticsAuditLog::query()
            ->where('action', 'expertis_gestion_report_sent')
            ->whereJsonContains('metadata->reportDate', $date)
            ->whereJsonContains('metadata->cut', $cut)
            ->exists();
    }

    public function wasExpertisClientReportSent(string $date): bool
    {
        return AnalyticsAuditLog::query()
            ->where('action', 'expertis_client_report_sent')
            ->whereJsonContains('metadata->reportDate', $date)
            ->exists();
    }
}
