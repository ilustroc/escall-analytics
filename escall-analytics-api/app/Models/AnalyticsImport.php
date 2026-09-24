<?php

namespace App\Models;

use Database\Factories\AnalyticsImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsImport extends Model
{
    /** @use HasFactory<AnalyticsImportFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'period',
        'original_filename',
        'stored_filename',
        'file_sha256',
        'file_size',
        'rows_total',
        'rows_processed',
        'rows_inserted',
        'rows_rejected',
        'progress',
        'status',
        'error_summary',
        'metadata',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<AnalyticsUser, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AnalyticsUser::class, 'created_by');
    }
}
