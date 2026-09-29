<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsImport;
use App\Models\AnalyticsUser;
use DateTimeImmutable;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Streams payment files into a private, per-import staging area. */
final class PaymentImportService
{
    public const TYPE = 'expertis_pagos';

    private const STAGING_TABLE = 'analytics_expertis_payment_staging';

    /** @var list<string> */
    private const HEADERS = ['FECHA_PAGO', 'CODIGO', 'MONTO', 'EJECUTIVO', 'TIPO_ACUERDO'];

    /** @var list<string> */
    private const AGREEMENTS = ['PAR', 'PPC', 'PPM'];

    /** @return array{maxFileMb:int,maxRows:int,chunkSize:int,allowedExtensions:list<string>} */
    public function configuration(): array
    {
        return [
            'maxFileMb' => (int) config('analytics.import_max_mb'),
            'maxRows' => (int) config('analytics.import_max_rows'),
            'chunkSize' => $this->chunkSize(),
            'allowedExtensions' => ['csv', 'txt'],
        ];
    }

    public function templateCsv(): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No se pudo generar la plantilla CSV.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, self::HEADERS, ';');
            fputcsv($stream, ['2026-09-02', '00000000-CUENTA DE EJEMPLO', '100.00', 'EJECUTIVO DE EJEMPLO', 'PPC'], ';');
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function createUpload(UploadedFile $file, AnalyticsUser $actor): AnalyticsImport
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! $file->isValid() || ! in_array($extension, ['csv', 'txt'], true)) {
            throw new RuntimeException('Formato no permitido. Utiliza un archivo CSV o TXT.');
        }

        $lock = Cache::lock('analytics-payment-upload:'.$actor->id, 30);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe una carga de pagos en proceso.');
        }
        try {
            $stored = trim((string) config('analytics.import_storage_directory'), '/').'/'.Str::uuid().'.'.$extension;
            Storage::disk('local')->putFileAs(dirname($stored), $file, basename($stored));
            $path = Storage::disk('local')->path($stored);
            $this->assertDelimitedTextFile($path, $file->getMimeType());
            $hash = hash_file('sha256', $path);
            if (AnalyticsImport::query()->where('type', self::TYPE)->where('file_sha256', $hash)->whereIn('status', ['completed', 'completed_with_errors'])->exists()) {
                Storage::disk('local')->delete($stored);
                throw new RuntimeException('Este archivo ya fue importado correctamente.');
            }

            return AnalyticsImport::query()->create([
                'type' => self::TYPE,
                // Payments may contain several months. The period belongs to each staged row.
                'period' => 'MULTI',
                'original_filename' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'stored_filename' => $stored,
                'file_sha256' => $hash,
                'file_size' => (int) filesize($path),
                'status' => 'uploaded',
                'created_by' => $actor->id,
                'metadata' => ['validation' => ['errors' => []]],
            ]);
        } finally {
            $lock->release();
        }
    }

    public function validate(AnalyticsImport $import): void
    {
        if ($import->type !== self::TYPE || $import->stored_filename === null) {
            throw new RuntimeException('La importación no contiene un archivo válido.');
        }
        $started = microtime(true);
        $this->update($import, ['status' => 'validating', 'progress' => 0, 'rows_processed' => 0, 'rows_total' => 0, 'rows_inserted' => 0, 'rows_rejected' => 0, 'started_at' => now()]);
        DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->delete();
        $path = Storage::disk('local')->path($import->stored_filename);
        $this->verifyStoredFile($import, $path);

        $result = $this->streamToStaging($import, $path);
        $stats = $this->stagingStats($import);
        $metadata = [
            'validation' => [
                'clients' => $stats['clients'], 'accounts' => $stats['accounts'], 'duplicates' => $result['duplicates'],
                'validPayments' => $stats['validPayments'], 'validAmount' => $stats['validAmount'],
                'byAgreement' => $stats['byAgreement'], 'errors' => array_slice($result['errors'], 0, 100),
            ],
            'errorFile' => $result['errorFile'],
            'metrics' => [
                'validationMs' => $this->milliseconds($started), 'streamingMs' => $result['streamingMs'],
                'stagingMs' => $result['stagingMs'], 'stagingQueries' => $result['stagingQueries'],
                'rowsPerSecond' => $result['rowsTotal'] > 0 ? round($result['rowsTotal'] / max(.001, $this->milliseconds($started) / 1000), 2) : 0,
                'peakMemoryMb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ],
        ];
        $fatal = $result['fatal'] || $result['rowsTotal'] === 0;
        if (! $fatal && $stats['validPayments'] === 0) {
            $this->update($import, [
                'rows_total' => $result['rowsTotal'], 'rows_processed' => $result['rowsTotal'],
                'rows_inserted' => 0, 'rows_rejected' => $result['rowsRejected'], 'progress' => 100,
                'status' => 'completed_with_errors', 'error_summary' => null,
                'finished_at' => now(), 'metadata' => $metadata,
            ]);
            DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->delete();
            $this->deleteSourceFile($import);

            return;
        }

        $this->update($import, [
            'rows_total' => $result['rowsTotal'], 'rows_processed' => $result['rowsTotal'],
            'rows_inserted' => $stats['validPayments'], 'rows_rejected' => $result['rowsRejected'], 'progress' => 100,
            'status' => $fatal ? 'failed' : 'validated',
            'error_summary' => $fatal
                ? ($result['rowsTotal'] === 0 ? 'El archivo no contiene filas de datos.' : 'El archivo no tiene una estructura válida.')
                : null,
            'finished_at' => now(), 'metadata' => $metadata,
        ]);
    }

    public function process(AnalyticsImport $import, AnalyticsCache $cache): void
    {
        if ($import->type !== self::TYPE || ! in_array($import->status, ['validated', 'processing'], true)) {
            throw new RuntimeException('La importación no está lista para ejecutarse.');
        }
        $started = microtime(true);
        $this->update($import, ['status' => 'processing', 'progress' => 99, 'started_at' => now()]);
        $inserted = 0;
        DB::transaction(function () use ($import, &$inserted): void {
            DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->where('is_valid', true)
                ->orderBy('id')->chunkById($this->chunkSize(), function ($rows) use (&$inserted): void {
                    $payload = $rows->map(static fn (object $row): array => [
                        'fecha' => $row->fecha_pago, 'cuenta' => $row->codigo, 'cuenta_normalizada' => $row->codigo_normalizado,
                        'dni' => $row->dni, 'monto' => $row->monto, 'ejecutivo' => $row->ejecutivo,
                        'tipo_acuerdo' => $row->tipo_acuerdo, 'hash_fila' => $row->logical_hash,
                        'numero_fila_origen' => $row->row_number, 'created_at' => now(), 'updated_at' => now(),
                    ])->all();
                    if ($payload !== []) {
                        $inserted += DB::table('pagos_expertis')->insertOrIgnore($payload);
                    }
                });
        }, 3);
        $stats = $this->stagingStats($import);
        $rowsRejected = (int) $import->rows_rejected + max(0, $stats['validPayments'] - $inserted);
        $this->update($import, [
            'status' => $rowsRejected > 0 ? 'completed_with_errors' : 'completed', 'progress' => 100, 'rows_processed' => (int) $import->rows_total,
            'rows_inserted' => $inserted, 'rows_rejected' => $rowsRejected,
            'finished_at' => now(),
            'metadata' => $this->metadata($import, ['metrics' => ['importMs' => $this->milliseconds($started), 'peakMemoryMb' => round(memory_get_peak_usage(true) / 1048576, 2)]]),
        ]);
        $cache->invalidate();
        DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->delete();
        $this->deleteSourceFile($import);
    }

    public function fail(AnalyticsImport $import, string $stage): void
    {
        $this->update($import, [
            'status' => 'failed', 'error_summary' => 'No se pudo completar la importación de pagos.', 'finished_at' => now(),
            'metadata' => $this->metadata($import, ['failure' => ['stage' => $stage]]),
        ]);
    }

    public function cleanup(int $hours): int
    {
        $imports = AnalyticsImport::query()->where('type', self::TYPE)->whereIn('status', ['failed', 'cancelled'])->where('updated_at', '<', now()->subHours($hours))->get(['id', 'metadata']);
        $ids = $imports->pluck('id');

        foreach ($imports as $import) {
            $errorFile = (array) (($import->metadata ?? [])['errorFile'] ?? []);
            if (isset($errorFile['storedFilename'])) {
                Storage::disk('local')->delete((string) $errorFile['storedFilename']);
            }
        }

        $deleted = $ids->isEmpty() ? 0 : DB::table(self::STAGING_TABLE)->whereIn('import_id', $ids)->delete();
        // Keep maintenance safe even where a pre-existing MariaDB table did
        // not retain the intended cascade constraint.
        $deleted += DB::table(self::STAGING_TABLE)->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('analytics_imports as i')->whereColumn('i.id', self::STAGING_TABLE.'.import_id');
        })->delete();

        return $deleted;
    }

    /** @return array{rowsTotal:int,rowsRejected:int,duplicates:int,errors:list<array{row:int|null,column:string,message:string}>,fatal:bool,errorFile:array{storedFilename:string,downloadFilename:string}|null,streamingMs:int,stagingMs:int,stagingQueries:int} */
    private function streamToStaging(AnalyticsImport $import, string $path): array
    {
        $started = microtime(true);
        $stagingMs = 0;
        $stagingQueries = 0;
        $rowsTotal = 0;
        $rowsRejected = 0;
        $duplicates = 0;
        $errors = [];
        $batch = [];
        $errorWriter = null;
        $errorFile = null;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo.');
        }
        try {
            $first = fgets($handle);
            if ($first === false) {
                return compact('rowsTotal', 'rowsRejected', 'duplicates', 'errors', 'errorFile') + ['fatal' => true, 'streamingMs' => $this->milliseconds($started), 'stagingMs' => 0, 'stagingQueries' => 0];
            }
            $encoding = $this->detectEncoding($path);
            $delimiter = $this->detectDelimiter($this->text($first, $encoding));
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter);
            $headers = is_array($headers) ? array_map(fn ($v) => strtoupper($this->text((string) $v, $encoding)), $headers) : [];
            if ($headers !== self::HEADERS) {
                $errors[] = ['row' => 1, 'column' => 'headers', 'message' => 'Los encabezados deben ser: '.implode(', ', self::HEADERS).'.'];

                return compact('rowsTotal', 'rowsRejected', 'duplicates', 'errors', 'errorFile') + ['fatal' => true, 'streamingMs' => $this->milliseconds($started), 'stagingMs' => 0, 'stagingQueries' => 0];
            }
            $errorWriter = ImportErrorCsvWriter::create($import->id, self::TYPE, self::HEADERS);
            $line = 1;
            $lastProgress = 0;
            $size = max(1, (int) filesize($path));
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $line++;
                $values = array_map(fn ($v) => $this->text((string) $v, $encoding), $values);
                if ($this->empty($values)) {
                    continue;
                }
                if (++$rowsTotal > (int) config('analytics.import_max_rows')) {
                    throw new RuntimeException('El archivo supera el máximo de filas permitido.');
                }
                $batch[] = ['data' => $this->rawRow($import->id, $line, $values), 'source' => array_combine(self::HEADERS, array_pad($values, count(self::HEADERS), '')) ?: []];
                if (count($batch) >= $this->chunkSize()) {
                    [$rejected, $dupes, $queries, $elapsed, $newErrors] = $this->validateAndInsert($import, $batch, $errorWriter);
                    $rowsRejected += $rejected;
                    $duplicates += $dupes;
                    $stagingQueries += $queries;
                    $stagingMs += $elapsed;
                    array_push($errors, ...$newErrors);
                    $batch = [];
                }
                if ($rowsTotal - $lastProgress >= 5000) {
                    $this->update($import, ['rows_processed' => $rowsTotal, 'rows_rejected' => $rowsRejected, 'progress' => min(99, (int) floor((ftell($handle) / $size) * 100))]);
                    $lastProgress = $rowsTotal;
                }
            }
            if ($batch !== []) {
                [$rejected, $dupes, $queries, $elapsed, $newErrors] = $this->validateAndInsert($import, $batch, $errorWriter);
                $rowsRejected += $rejected;
                $duplicates += $dupes;
                $stagingQueries += $queries;
                $stagingMs += $elapsed;
                array_push($errors, ...$newErrors);
            }
        } finally {
            fclose($handle);
            if ($errorWriter !== null) {
                $errorFile = $errorWriter->close();
            }
        }

        return compact('rowsTotal', 'rowsRejected', 'duplicates', 'errors', 'errorFile') + ['fatal' => false, 'streamingMs' => $this->milliseconds($started), 'stagingMs' => $stagingMs, 'stagingQueries' => $stagingQueries];
    }

    /** @param list<array{data: array<string,mixed>, source: array<string,mixed>}> $batch
     * @return array{int,int,int,int,list<array{row:int|null,column:string,message:string}>}
     */
    private function validateAndInsert(AnalyticsImport $import, array $batch, ImportErrorCsvWriter $errorWriter): array
    {
        $started = microtime(true);
        $errors = [];
        $queries = 0;
        $duplicates = 0;
        $rejected = 0;
        $validCodes = [];
        $stagingRows = array_column($batch, 'data');
        foreach ($stagingRows as $row) {
            if ($row['period'] !== null && $row['codigo_normalizado'] !== null && $row['error_message'] === null) {
                $validCodes[$row['period']][] = $row['codigo_normalizado'];
            }
        }
        $assignments = [];
        foreach ($validCodes as $period => $codes) {
            $rows = DB::table((string) config('analytics.assignment_table'))->where('FEC_PRO', $period)->whereIn('conca', array_values(array_unique($codes)))->get(['conca', 'Documento_RUC']);
            $queries++;
            foreach ($rows as $row) {
                $assignments[$period.'|'.$row->conca] = $row->Documento_RUC;
            }
        }
        $hashes = array_values(array_unique(array_filter(array_column($stagingRows, 'logical_hash'))));
        $seen = $hashes === [] ? [] : array_fill_keys(DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->whereIn('logical_hash', $hashes)->pluck('logical_hash')->all(), true);
        if ($hashes !== []) {
            $queries++;
        }
        $final = [];
        if ($hashes !== []) {
            // hash_fila predates Analytics in some installations, so compare
            // the five business fields as well as our logical hash.
            $dates = array_values(array_unique(array_filter(array_column($stagingRows, 'fecha_pago'))));
            $codes = array_values(array_unique(array_filter(array_column($stagingRows, 'codigo_normalizado'))));
            $existing = DB::table('pagos_expertis')->whereIn('hash_fila', $hashes)
                ->orWhere(function ($query) use ($dates, $codes): void {
                    $query->whereIn('fecha', $dates)->whereIn('cuenta_normalizada', $codes);
                })->get(['fecha', 'cuenta_normalizada', 'monto', 'ejecutivo', 'tipo_acuerdo', 'hash_fila']);
            $queries++;
            foreach ($existing as $payment) {
                $final[(string) $payment->hash_fila] = true;
                $final[$this->logicalHash((string) $payment->fecha, (string) $payment->cuenta_normalizada, (string) $payment->monto, (string) $payment->ejecutivo, (string) $payment->tipo_acuerdo)] = true;
            }
        }
        foreach ($batch as &$entry) {
            $row = &$entry['data'];
            if ($row['error_message'] === null && ! isset($assignments[$row['period'].'|'.$row['codigo_normalizado']])) {
                $row['error_message'] = 'El código no se encuentra en la asignación del periodo '.$row['period'].'.';
            }
            if ($row['error_message'] === null && isset($seen[$row['logical_hash']])) {
                $row['error_message'] = 'Pago duplicado dentro del archivo.';
                $duplicates++;
            }
            if ($row['error_message'] === null && isset($final[$row['logical_hash']])) {
                $row['error_message'] = 'El pago ya existe.';
                $duplicates++;
            }
            if ($row['error_message'] === null) {
                $row['dni'] = $assignments[$row['period'].'|'.$row['codigo_normalizado']];
                $row['is_valid'] = true;
                $seen[$row['logical_hash']] = true;
            } else {
                $rejected++;
                $errors[] = ['row' => $row['row_number'], 'column' => 'registro', 'message' => $row['error_message']];
                $errorWriter->append((int) $row['row_number'], $entry['source'], 'registro', (string) $row['error_message']);
            }
            $row['created_at'] = now();
            $row['updated_at'] = now();
        } unset($entry, $row);
        DB::table(self::STAGING_TABLE)->insert(array_column($batch, 'data'));
        $queries++;

        return [$rejected, $duplicates, $queries, $this->milliseconds($started), array_slice($errors, 0, 100)];
    }

    /** @param list<string> $values @return array<string,mixed> */
    private function rawRow(int $importId, int $line, array $values): array
    {
        $date = $this->date($values[0] ?? '');
        $code = $this->upper($values[1] ?? '');
        $amount = $this->amount($values[2] ?? '');
        $executive = $this->upper($values[3] ?? '');
        $agreement = $this->upper($values[4] ?? '');
        $error = null;
        if ($date === null) {
            $error = 'Fecha de pago inválida. Utiliza YYYY-MM-DD o DD/MM/YYYY.';
        } elseif ($code === '') {
            $error = 'Código obligatorio.';
        } elseif ($amount === null || (float) $amount <= 0) {
            $error = 'Monto inválido. Debe ser mayor que cero.';
        } elseif ($executive === '') {
            $error = 'Ejecutivo obligatorio.';
        } elseif (! in_array($agreement, self::AGREEMENTS, true)) {
            $error = 'Tipo de acuerdo inválido. Valores permitidos: PAR, PPC, PPM.';
        }
        $period = $date === null ? null : substr($date, 0, 4).substr($date, 5, 2);
        $hash = $date !== null && $code !== '' && $amount !== null && $executive !== '' && in_array($agreement, self::AGREEMENTS, true) ? $this->logicalHash($date, $code, $amount, $executive, $agreement) : null;

        return ['import_id' => $importId, 'row_number' => $line, 'fecha_pago' => $date, 'period' => $period, 'codigo' => $code ?: null, 'codigo_normalizado' => $code ?: null, 'dni' => null, 'monto' => $amount, 'ejecutivo' => $executive ?: null, 'tipo_acuerdo' => $agreement ?: null, 'logical_hash' => $hash, 'is_valid' => false, 'error_message' => $error];
    }

    /** @return array{validPayments:int,validAmount:float,clients:int,accounts:int,byAgreement:array<string,int>} */
    private function stagingStats(AnalyticsImport $import): array
    {
        $row = DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->where('is_valid', true)->selectRaw("COUNT(*) total, COALESCE(SUM(monto),0) amount, COUNT(DISTINCT NULLIF(dni,'')) clients, COUNT(DISTINCT NULLIF(codigo_normalizado,'')) accounts, SUM(CASE WHEN tipo_acuerdo='PPC' THEN 1 ELSE 0 END) ppc, SUM(CASE WHEN tipo_acuerdo='PPM' THEN 1 ELSE 0 END) ppm, SUM(CASE WHEN tipo_acuerdo='PAR' THEN 1 ELSE 0 END) par")->first();

        return ['validPayments' => (int) ($row->total ?? 0), 'validAmount' => round((float) ($row->amount ?? 0), 2), 'clients' => (int) ($row->clients ?? 0), 'accounts' => (int) ($row->accounts ?? 0), 'byAgreement' => ['PPC' => (int) ($row->ppc ?? 0), 'PPM' => (int) ($row->ppm ?? 0), 'PAR' => (int) ($row->par ?? 0)]];
    }

    private function verifyStoredFile(AnalyticsImport $import, string $path): void
    {
        if (! is_file($path) || ! hash_equals($import->file_sha256, hash_file('sha256', $path))) {
            throw new RuntimeException('No se pudo verificar el archivo de importación.');
        }
        $this->assertDelimitedTextFile($path, null);
    }

    private function assertDelimitedTextFile(string $path, ?string $reportedMime): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('El archivo está vacío.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
        if (($reportedMime !== null && ! in_array($reportedMime, $allowed, true)) || ! in_array($mime, $allowed, true)) {
            throw new RuntimeException('Formato no permitido. Utiliza un archivo CSV o TXT.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 16);
        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "\xD0\xCF\x11\xE0") || str_contains($head, "\0")) {
            throw new RuntimeException('Formato no permitido. Utiliza un archivo CSV o TXT.');
        }
    }

    private function detectEncoding(string $path): string
    {
        $sample = (string) file_get_contents($path, false, null, 0, 65536);

        return str_starts_with($sample, "\xEF\xBB\xBF") || mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    private function detectDelimiter(string $head): string
    {
        $found = [';' => substr_count($head, ';'), ',' => substr_count($head, ','), '|' => substr_count($head, '|'), "\t" => substr_count($head, "\t")];
        arsort($found);
        $delimiter = array_key_first($found);
        if ($delimiter === null || $found[$delimiter] === 0) {
            throw new RuntimeException('No se pudo detectar el delimitador. Utiliza ;, coma, | o tabulador.');
        }

        return $delimiter;
    }

    private function text(string $value, string $encoding): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        return trim($encoding === 'UTF-8' ? $value : mb_convert_encoding($value, 'UTF-8', $encoding));
    }

    /** @param list<string> $values */
    private function empty(array $values): bool
    {
        return array_filter($values, static fn (string $v): bool => $v !== '') === [];
    }

    private function upper(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value));
            if ($date !== false && $date->format($format) === trim($value)) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function amount(string $value): ?string
    {
        $value = preg_replace('/^\s*(S\/|S\/\.)\s*/iu', '', trim($value)) ?? trim($value);
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.') ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '.', $value);
        } if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function logicalHash(string $date, string $code, string $amount, string $executive, string $agreement): string
    {
        return hash('sha256', implode('|', [$date, $this->upper($code), number_format((float) $amount, 2, '.', ''), $this->upper($executive), $this->upper($agreement)]));
    }

    private function chunkSize(): int
    {
        return min(2000, max(500, (int) config('analytics.payment_import_chunk_size')));
    }

    /** @param array<string,mixed> $data */
    private function update(AnalyticsImport $import, array $data): void
    {
        AnalyticsImport::query()->whereKey($import->id)->update($data + ['updated_at' => now()]);
        $import->refresh();
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function metadata(AnalyticsImport $import, array $extra): array
    {
        return array_replace_recursive((array) $import->metadata, $extra);
    }

    private function deleteSourceFile(AnalyticsImport $import): void
    {
        if ($import->stored_filename === null) {
            return;
        }

        Storage::disk('local')->delete($import->stored_filename);
        $this->update($import, ['stored_filename' => null]);
    }

    private function milliseconds(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
