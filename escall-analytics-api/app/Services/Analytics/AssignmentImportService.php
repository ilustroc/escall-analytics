<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsImport;
use App\Models\AnalyticsUser;
use DateTimeInterface;
use finfo;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AssignmentImportService
{
    public const TYPE = 'expertis_asignacion';

    private const STAGING_TABLE = 'analytics_expertis_assignment_staging';

    /** @var list<string> */
    private const REQUIRED_HEADERS = ['Documento_RUC', 'CARTERA', 'NombreDeudor', 'conca'];

    /** @var list<string> */
    private const NUMERIC_COLUMNS = [
        'IdDeudor', 'DK', 'DT', 'C1', 'C2', 'C3', 'C6', 'C12', 'C24',
        'nvl_Asesor', 'nvl_Supervisor', 'nvl_Jefe', 'nvl_Gerente', 'Percentil',
    ];

    /** @var list<string> */
    private const NUMERIC_NULL_MARKERS = ['EVALUA'];

    /** @var list<string> */
    private const IMPORTABLE_COLUMNS = [
        'IdDeudor', 'Documento_RUC', 'CARTERA', 'NombreDeudor', 'conca', 'cosecha', 'Canal', 'MOVIMIENTO CANAL',
        'Grupo', 'CodigoGrupo', 'Segmento', 'Subsegmento', 'DK', 'DT', 'RANGO_DEUDA', 'antiguedad', 'fechaCastigo',
        'C1', 'C2', 'C3', 'C6', 'C12', 'C24', 'nvl_Asesor', 'nvl_Supervisor', 'nvl_Jefe', 'nvl_Gerente', 'nvl_Socio',
        'CEF_INICIO', 'Percentil', 'telef1cef', 'telef2cef', 'telef1prim', 'telef2prim', 'telef1yp', 'telef2yp',
        'telef1admin', 'telef2admin', 'telef1search', 'telef2search',
    ];

    /** @var list<string> */
    private const ACTIVE_STATUSES = ['uploaded', 'validating', 'validated', 'processing'];

    /** @return array{maxFileMb: int, maxRows: int, chunkSize: int, allowedExtensions: list<string>} */
    public function configuration(): array
    {
        return [
            'maxFileMb' => (int) config('analytics.import_max_mb'),
            'maxRows' => (int) config('analytics.import_max_rows'),
            'chunkSize' => $this->stagingChunkSize(),
            'allowedExtensions' => ['csv', 'txt'],
        ];
    }

    public function templateCsv(): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('No se pudo generar la plantilla CSV.');
        }
        $example = array_fill_keys(self::IMPORTABLE_COLUMNS, '');
        $example = [
            ...$example,
            'IdDeudor' => '1',
            'Documento_RUC' => '00000000',
            'CARTERA' => 'CARTERA DE EJEMPLO',
            'NombreDeudor' => 'CLIENTE DE EJEMPLO',
            'conca' => 'CUENTA-EJEMPLO-001',
            'cosecha' => 'COSECHA DE EJEMPLO',
            'Canal' => 'EXPERTIS',
        ];
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::IMPORTABLE_COLUMNS, ';');
            fputcsv($handle, array_values($example), ';');
            rewind($handle);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    public function createUpload(UploadedFile $file, string $period, AnalyticsUser $actor): AnalyticsImport
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! $file->isValid() || ! in_array($extension, ['csv', 'txt'], true)) {
            throw new RuntimeException('Formato no permitido. Utiliza un archivo CSV o TXT.');
        }

        $lock = Cache::lock('analytics-import-period:'.self::TYPE.':'.$period, 15);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe una solicitud de importación para este periodo.');
        }

        try {
            if ($this->hasActiveImport($period)) {
                throw new RuntimeException('Ya existe una importación en curso o pendiente para este periodo.');
            }

            $storedFilename = trim((string) config('analytics.import_storage_directory'), '/').'/'.Str::uuid().'.'.$extension;
            Storage::disk('local')->putFileAs(dirname($storedFilename), $file, basename($storedFilename));
            $path = Storage::disk('local')->path($storedFilename);
            $this->assertDelimitedTextFile($path, null);
            $hash = hash_file('sha256', $path);

            if ($this->isCompletedDuplicate($period, $hash)) {
                Storage::disk('local')->delete($storedFilename);
                throw new RuntimeException('Este archivo ya fue importado correctamente para el periodo seleccionado.');
            }

            return AnalyticsImport::query()->create([
                'type' => self::TYPE,
                'period' => $period,
                'original_filename' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'stored_filename' => $storedFilename,
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

        $startedAt = microtime(true);
        $this->markProgress($import, 'validating', 0, 0, 0, now());
        // Recover safely after an interrupted worker. This touches only the
        // private staging rows for this import; the official table is not
        // changed until the later, explicitly confirmed atomic swap.
        DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->delete();
        $path = Storage::disk('local')->path($import->stored_filename);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            $this->verifyStoredFile($import, $path, $extension);
            $stream = $this->streamToStaging($import, $path);
            $stats = $this->stagingStats($import);
            $errors = $stream['errors'];
            if ($stream['rowsTotal'] !== $stats['rows'] + $stream['rowsRejected'] && $errors === []) {
                $this->appendError($errors, null, 'archivo', 'La cantidad cargada en staging no coincide con el archivo.');
            }

            if ($errors !== [] || $stream['rowsTotal'] !== $stats['rows'] + $stream['rowsRejected']) {
                $this->completeValidationFailure($import, [
                    'rowsTotal' => $stream['rowsTotal'],
                    'rowsRejected' => $stream['rowsRejected'],
                    'clients' => $stats['clients'],
                    'accounts' => $stats['accounts'],
                    'duplicates' => $stream['duplicates'],
                    'errors' => $errors,
                ], $startedAt);

                return;
            }

            $this->updateImport($import, [
                'rows_total' => $stream['rowsTotal'],
                'rows_processed' => $stream['rowsTotal'],
                'rows_inserted' => $stats['rows'],
                'rows_rejected' => 0,
                'progress' => 100,
                'status' => 'validated',
                'error_summary' => null,
                'finished_at' => now(),
                'metadata' => $this->metadata($import, [
                    'validation' => [
                        'clients' => $stats['clients'],
                        'accounts' => $stats['accounts'],
                        'duplicates' => $stream['duplicates'],
                        'errors' => [],
                    ],
                    'metrics' => [
                        ...$stream['metrics'],
                        'validationMs' => $this->milliseconds($startedAt),
                        'rowsPerSecond' => $stream['rowsTotal'] > 0 ? round($stream['rowsTotal'] / max(0.001, $this->milliseconds($startedAt) / 1000), 2) : 0,
                        'peakMemoryMb' => $this->peakMemoryMb(),
                    ],
                ]),
            ]);
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    public function process(AnalyticsImport $import, AnalyticsCache $cache): void
    {
        if ($import->type !== self::TYPE || ! in_array($import->status, ['validated', 'processing'], true)) {
            throw new RuntimeException('La importación no está lista para reemplazar el periodo.');
        }

        $startedAt = microtime(true);
        $this->markProgress($import, 'processing', (int) $import->rows_total, (int) $import->rows_total, 0, now());
        $table = $this->assignmentTable();
        $stagingRows = (int) DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->count();
        if ($stagingRows !== (int) $import->rows_total) {
            throw new RuntimeException('El staging no coincide con la validación aprobada.');
        }

        try {
            DB::transaction(function () use ($table, $import, $stagingRows): void {
                DB::table($table)->where('FEC_PRO', $import->period)->delete();
                $inserted = $this->copyStagingToAssignment($import);
                if ($inserted !== $stagingRows) {
                    throw new RuntimeException('La cantidad insertada no coincide con el staging validado.');
                }
            }, 3);
        } catch (Throwable $exception) {
            throw $exception;
        }

        $stats = $this->assignmentPeriodStats($import->period);
        if ($stats['rows'] !== $stagingRows) {
            throw new RuntimeException('La validación posterior no coincide con la cantidad importada.');
        }

        $this->updateImport($import, [
            'rows_inserted' => $stats['rows'],
            'rows_processed' => $stats['rows'],
            'progress' => 100,
            'status' => 'completed',
            'finished_at' => now(),
            'metadata' => $this->metadata($import, [
                'validation' => [
                    ...$this->validationMetadata($import),
                    'clients' => $stats['clients'],
                    'accounts' => $stats['accounts'],
                ],
                'metrics' => [
                    ...$this->metricsMetadata($import),
                    'swapMs' => $this->milliseconds($startedAt),
                    'peakMemoryMb' => $this->peakMemoryMb(),
                ],
            ]),
        ]);

        $cache->invalidate();
        DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->delete();
        if ($import->stored_filename !== null) {
            Storage::disk('local')->delete($import->stored_filename);
            $this->updateImport($import, ['stored_filename' => null]);
        }
    }

    public function fail(AnalyticsImport $import, string $message, string $stage): void
    {
        DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->delete();
        $this->updateImport($import, [
            'status' => 'failed',
            'error_summary' => $message,
            'finished_at' => now(),
            'metadata' => $this->metadata($import, [
                'failure' => ['stage' => $stage],
                'metrics' => [...$this->metricsMetadata($import), 'peakMemoryMb' => $this->peakMemoryMb()],
            ]),
        ]);
    }

    public function existingRows(string $period): int
    {
        return (int) DB::table($this->assignmentTable())->where('FEC_PRO', $period)->count();
    }

    public function cleanup(int $hours): int
    {
        $imports = AnalyticsImport::query()
            ->whereIn('status', ['failed', 'cancelled'])
            ->where('updated_at', '<', now()->subHours($hours))
            ->get(['id', 'stored_filename']);

        if ($imports->isEmpty()) {
            return 0;
        }

        foreach ($imports as $import) {
            if ($import->stored_filename !== null) {
                Storage::disk('local')->delete($import->stored_filename);
            }
        }
        AnalyticsImport::query()->whereIn('id', $imports->pluck('id'))->update(['stored_filename' => null]);

        return DB::table(self::STAGING_TABLE)->whereIn('import_id', $imports->pluck('id'))->delete();
    }

    /** @return array{rows: int, clients: int, accounts: int} */
    private function stagingStats(AnalyticsImport $import): array
    {
        $stats = DB::table(self::STAGING_TABLE)->where('import_id', $import->id)
            ->selectRaw("COUNT(*) as rows_count, COUNT(DISTINCT NULLIF(`Documento_RUC`, '')) as clients, COUNT(DISTINCT NULLIF(`conca`, '')) as accounts")
            ->first();

        return ['rows' => (int) ($stats->rows_count ?? 0), 'clients' => (int) ($stats->clients ?? 0), 'accounts' => (int) ($stats->accounts ?? 0)];
    }

    /** @return array{rows: int, clients: int, accounts: int} */
    private function assignmentPeriodStats(string $period): array
    {
        $stats = DB::table($this->assignmentTable())->where('FEC_PRO', $period)
            ->selectRaw("COUNT(*) as rows_count, COUNT(DISTINCT NULLIF(`Documento_RUC`, '')) as clients, COUNT(DISTINCT NULLIF(`conca`, '')) as accounts")
            ->first();

        return ['rows' => (int) ($stats->rows_count ?? 0), 'clients' => (int) ($stats->clients ?? 0), 'accounts' => (int) ($stats->accounts ?? 0)];
    }

    /**
     * @return array{rowsTotal: int, rowsRejected: int, duplicates: int, errors: list<array{row: int|null, column: string, message: string}>, metrics: array<string, int|float>}
     */
    private function streamToStaging(AnalyticsImport $import, string $path): array
    {
        $startedAt = microtime(true);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo para validación.');
        }

        $rowsTotal = 0;
        $rowsRejected = 0;
        $duplicates = 0;
        $errors = [];
        $batch = [];
        $inserted = 0;
        $stagingMs = 0;
        $stagingQueries = 0;
        $lastReported = 0;
        $chunkSize = $this->stagingChunkSize();
        $fileSize = max(1, (int) filesize($path));

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                $this->appendError($errors, 1, 'archivo', 'El archivo está vacío.');

                return compact('rowsTotal', 'rowsRejected', 'duplicates', 'errors') + ['metrics' => $this->streamMetrics($startedAt, $stagingMs, $stagingQueries)];
            }

            $encoding = $this->detectEncoding($path);
            $delimiter = $this->detectDelimiter($this->normalizeText($firstLine, $encoding));
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter);
            if ($headers === false) {
                $this->appendError($errors, 1, 'archivo', 'No se encontró una fila de headers válida.');

                return compact('rowsTotal', 'rowsRejected', 'duplicates', 'errors') + ['metrics' => $this->streamMetrics($startedAt, $stagingMs, $stagingQueries)];
            }

            $headers = array_map(fn (?string $value): string => $this->normalizeText((string) $value, $encoding), $headers);
            $headerMap = $this->resolveHeaderMap($headers);
            $mapped = array_fill_keys(array_map($this->headerKey(...), array_values($headerMap)), true);
            foreach (self::REQUIRED_HEADERS as $header) {
                if (! isset($mapped[$this->headerKey($header)])) {
                    $this->appendError($errors, 1, $header, 'Header obligatorio ausente.');
                }
            }
            if ($errors !== []) {
                return compact('rowsTotal', 'rowsRejected', 'duplicates', 'errors') + ['metrics' => $this->streamMetrics($startedAt, $stagingMs, $stagingQueries)];
            }

            $rowNumber = 1;
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;
                $values = array_map(fn (?string $value): string => $this->normalizeText((string) $value, $encoding), $values);
                if ($this->isEmpty($values)) {
                    continue;
                }
                $rowsTotal++;
                if ($rowsTotal > (int) config('analytics.import_max_rows')) {
                    throw new RuntimeException('El archivo supera el máximo de filas permitido.');
                }

                $row = $this->mapRow($values, $headerMap);
                $missing = array_filter(self::REQUIRED_HEADERS, fn (string $header): bool => ($row[$header] ?? null) === null || trim((string) $row[$header]) === '');
                $invalidNumericColumns = $this->normalizeNumericColumns($row);
                if ($missing !== [] || $invalidNumericColumns !== []) {
                    $rowsRejected++;
                    foreach ($missing as $column) {
                        $this->appendError($errors, $rowNumber, $column, 'Valor obligatorio ausente.');
                    }
                    foreach ($invalidNumericColumns as $column) {
                        $this->appendError($errors, $rowNumber, $column, 'El valor debe ser numérico o estar vacío.');
                    }
                } else {
                    $batch[] = ['rowNumber' => $rowNumber, 'data' => ['import_id' => $import->id, 'period' => $import->period, ...$row]];
                }

                if (count($batch) >= $chunkSize) {
                    [$written, $duplicatesInBatch, $queries, $elapsed] = $this->insertStagingBatch($import, $batch, $errors);
                    $inserted += $written;
                    $duplicates += $duplicatesInBatch;
                    $rowsRejected += $duplicatesInBatch;
                    $stagingQueries += $queries;
                    $stagingMs += $elapsed;
                    $batch = [];
                }

                if ($rowsTotal - $lastReported >= max(5000, $chunkSize * 2)) {
                    $this->markStreamingProgress($import, $rowsTotal, $inserted, $rowsRejected, (int) ftell($handle), $fileSize);
                    $lastReported = $rowsTotal;
                }
            }

            if ($batch !== []) {
                [$written, $duplicatesInBatch, $queries, $elapsed] = $this->insertStagingBatch($import, $batch, $errors);
                $inserted += $written;
                $duplicates += $duplicatesInBatch;
                $rowsRejected += $duplicatesInBatch;
                $stagingQueries += $queries;
                $stagingMs += $elapsed;
            }
            $this->markStreamingProgress($import, $rowsTotal, $inserted, $rowsRejected, $fileSize, $fileSize);
        } finally {
            fclose($handle);
        }

        return compact('rowsTotal', 'rowsRejected', 'duplicates', 'errors') + ['metrics' => $this->streamMetrics($startedAt, $stagingMs, $stagingQueries)];
    }

    /**
     * @param  list<array{rowNumber: int, data: array<string, mixed>}>  $batch
     * @param  list<array{row: int|null, column: string, message: string}>  $errors
     * @return array{int, int, int, int}
     */
    private function insertStagingBatch(AnalyticsImport $import, array $batch, array &$errors): array
    {
        $startedAt = microtime(true);
        $queries = 1;
        $duplicates = 0;
        $rows = array_column($batch, 'data');
        try {
            DB::table(self::STAGING_TABLE)->insert($rows);

            return [count($rows), 0, $queries, $this->milliseconds($startedAt)];
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }

        $accounts = array_values(array_unique(array_map(fn (array $row): string => (string) $row['conca'], $rows)));
        $existing = DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->whereIn('conca', $accounts)->pluck('conca')->all();
        $queries++;
        $existingLookup = array_fill_keys($existing, true);
        $batchLookup = [];
        $validRows = [];
        foreach ($batch as $entry) {
            $account = (string) $entry['data']['conca'];
            if (isset($existingLookup[$account]) || isset($batchLookup[$account])) {
                $duplicates++;
                $this->appendError($errors, $entry['rowNumber'], 'conca', 'Cuenta duplicada para el periodo seleccionado.');

                continue;
            }
            $batchLookup[$account] = true;
            $validRows[] = $entry['data'];
        }
        if ($validRows !== []) {
            DB::table(self::STAGING_TABLE)->insert($validRows);
            $queries++;
        }

        return [count($validRows), $duplicates, $queries, $this->milliseconds($startedAt)];
    }

    private function copyStagingToAssignment(AnalyticsImport $import): int
    {
        $grammar = DB::getQueryGrammar();
        $columns = ['FEC_PRO', ...self::IMPORTABLE_COLUMNS];
        $wrappedColumns = implode(', ', array_map($grammar->wrap(...), $columns));
        $selectColumns = implode(', ', [$grammar->wrap('period'), ...array_map($grammar->wrap(...), self::IMPORTABLE_COLUMNS)]);
        $target = $grammar->wrapTable($this->assignmentTable());
        $staging = $grammar->wrapTable(self::STAGING_TABLE);

        return DB::affectingStatement("INSERT INTO {$target} ({$wrappedColumns}) SELECT {$selectColumns} FROM {$staging} WHERE ".$grammar->wrap('import_id').' = ?', [$import->id]);
    }

    /** @param array{rowsTotal: int, rowsRejected?: int, clients?: int, accounts?: int, duplicates?: int, errors: list<array{row: int|null, column: string, message: string}>} $summary */
    private function completeValidationFailure(AnalyticsImport $import, array $summary, float $startedAt): void
    {
        DB::table(self::STAGING_TABLE)->where('import_id', $import->id)->delete();
        $this->updateImport($import, [
            'rows_total' => (int) $summary['rowsTotal'],
            'rows_processed' => (int) $summary['rowsTotal'],
            'rows_rejected' => (int) ($summary['rowsRejected'] ?? 0),
            'progress' => 100,
            'status' => 'failed',
            'error_summary' => 'El archivo contiene errores y no puede importarse.',
            'finished_at' => now(),
            'metadata' => $this->metadata($import, ['validation' => ['clients' => (int) ($summary['clients'] ?? 0), 'accounts' => (int) ($summary['accounts'] ?? 0), 'duplicates' => (int) ($summary['duplicates'] ?? 0), 'errors' => array_slice($summary['errors'], 0, 100)], 'metrics' => ['validationMs' => $this->milliseconds($startedAt), 'peakMemoryMb' => $this->peakMemoryMb()]]),
        ]);
    }

    private function verifyStoredFile(AnalyticsImport $import, string $path, string $extension): void
    {
        if (! is_file($path) || ! hash_equals($import->file_sha256, hash_file('sha256', $path))) {
            throw new RuntimeException('No se pudo verificar el archivo de importación.');
        }
        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw new RuntimeException('Formato no permitido. Utiliza un archivo CSV o TXT.');
        }
        $this->assertDelimitedTextFile($path, null);
    }

    private function assertDelimitedTextFile(string $path, ?string $reportedMime): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('El archivo está vacío.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (($reportedMime !== null && ! $this->isAllowedMime($reportedMime)) || ! $this->isAllowedMime($mime)) {
            throw new RuntimeException('Formato no permitido. Utiliza un archivo CSV o TXT.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo.');
        }
        try {
            $signature = (string) fread($handle, 16);
        } finally {
            fclose($handle);
        }
        if (str_starts_with($signature, "PK\x03\x04") || str_starts_with($signature, "\xD0\xCF\x11\xE0") || str_contains($signature, "\0")) {
            throw new RuntimeException('Formato no permitido. Utiliza un archivo CSV o TXT.');
        }
    }

    private function detectEncoding(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo analizar la codificación del archivo.');
        }
        try {
            $sample = (string) fread($handle, 65536);
        } finally {
            fclose($handle);
        }
        if (str_starts_with($sample, "\xEF\xBB\xBF") || mb_check_encoding($sample, 'UTF-8')) {
            return 'UTF-8';
        }

        return 'Windows-1252';
    }

    private function detectDelimiter(string $header): string
    {
        $candidates = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), '|' => substr_count($header, '|'), "\t" => substr_count($header, "\t")];
        arsort($candidates);
        $delimiter = array_key_first($candidates);
        if ($delimiter === null || $candidates[$delimiter] === 0) {
            throw new RuntimeException('No se pudo detectar el delimitador. Utiliza ;, coma, | o tabulador.');
        }

        return $delimiter;
    }

    private function normalizeText(string $value, string $encoding): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        if ($encoding !== 'UTF-8') {
            $value = mb_convert_encoding($value, 'UTF-8', $encoding);
        }

        return trim($value);
    }

    /** @param array<int, mixed> $headers
     * @return array<int, string>
     */
    private function resolveHeaderMap(array $headers): array
    {
        $lookup = [];
        foreach (self::IMPORTABLE_COLUMNS as $column) {
            $lookup[$this->headerKey($column)] = $column;
        }
        $map = [];
        foreach ($headers as $index => $header) {
            $key = $this->headerKey($this->stringValue($header));
            if (isset($lookup[$key])) {
                $map[$index] = $lookup[$key];
            }
        }

        return $map;
    }

    /** @param array<int, mixed> $values
     * @param  array<int, string>  $headerMap
     * @return array<string, mixed>
     */
    private function mapRow(array $values, array $headerMap): array
    {
        $row = array_fill_keys(self::IMPORTABLE_COLUMNS, null);
        foreach ($headerMap as $index => $column) {
            $value = $values[$index] ?? null;
            $row[$column] = is_string($value) && trim($value) === '' ? null : $this->stringValue($value);
        }

        return $row;
    }

    /**
     * The historical target defines these fields as numeric. Expertis uses
     * EVALUA as a legacy placeholder for a missing management level, so it
     * must become NULL before staging. Any other text is reported as a row
     * validation error instead of letting MariaDB fail an entire batch.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function normalizeNumericColumns(array &$row): array
    {
        $invalid = [];
        foreach (self::NUMERIC_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            if ($value === null) {
                continue;
            }

            $text = trim((string) $value);
            if (in_array(mb_strtoupper($text), self::NUMERIC_NULL_MARKERS, true)) {
                $row[$column] = null;

                continue;
            }

            $normalized = str_replace(',', '.', str_replace(' ', '', $text));
            if (! is_numeric($normalized)) {
                $invalid[] = $column;

                continue;
            }

            $row[$column] = in_array($column, ['IdDeudor', 'Percentil'], true)
                ? (int) $normalized
                : (float) $normalized;
        }

        return $invalid;
    }

    private function markProgress(AnalyticsImport $import, string $status, int $rowsTotal, int $rowsProcessed, int $rowsInserted, ?DateTimeInterface $startedAt): void
    {
        $this->updateImport($import, ['status' => $status, 'rows_total' => $rowsTotal, 'rows_processed' => $rowsProcessed, 'rows_inserted' => $rowsInserted, 'progress' => $rowsTotal > 0 ? min(99, (int) floor(($rowsProcessed / $rowsTotal) * 100)) : 0, ...($startedAt === null ? [] : ['started_at' => $startedAt])]);
    }

    private function markStreamingProgress(AnalyticsImport $import, int $rowsProcessed, int $rowsInserted, int $rowsRejected, int $bytesRead, int $fileSize): void
    {
        $this->updateImport($import, [
            'status' => 'validating',
            'rows_total' => 0,
            'rows_processed' => $rowsProcessed,
            'rows_inserted' => $rowsInserted,
            'rows_rejected' => $rowsRejected,
            'progress' => min(99, (int) floor(($bytesRead / max(1, $fileSize)) * 100)),
        ]);
    }

    /** @return array<string, int|float> */
    private function streamMetrics(float $startedAt, int $stagingMs, int $stagingQueries): array
    {
        $streamingMs = $this->milliseconds($startedAt);

        return [
            'streamingMs' => $streamingMs,
            'readingMs' => max(0, $streamingMs - $stagingMs),
            'stagingMs' => $stagingMs,
            'stagingQueries' => $stagingQueries,
            'peakMemoryMb' => $this->peakMemoryMb(),
        ];
    }

    private function stagingChunkSize(): int
    {
        $configured = max(1, (int) config('analytics.import_chunk_size'));
        $columnCount = count(self::IMPORTABLE_COLUMNS) + 2;
        if (DB::connection()->getDriverName() === 'sqlite') {
            return min($configured, max(1, intdiv(900, $columnCount)));
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            return min($configured, max(1, intdiv(60000, $columnCount)));
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return $configured;
        }

        return $configured;
    }

    /** @param array<string, mixed> $attributes */
    private function updateImport(AnalyticsImport $import, array $attributes): void
    {
        $import->forceFill($attributes)->save();
    }

    /** @param list<array{row: int|null, column: string, message: string}> $errors */
    private function appendError(array &$errors, ?int $row, string $column, string $message): void
    {
        if (count($errors) < 100) {
            $errors[] = ['row' => $row, 'column' => $column, 'message' => $message];
        }
    }

    private function hasActiveImport(string $period): bool
    {
        return AnalyticsImport::query()->where('type', self::TYPE)->where('period', $period)->whereIn('status', self::ACTIVE_STATUSES)->exists();
    }

    private function isCompletedDuplicate(string $period, string $hash): bool
    {
        return AnalyticsImport::query()->where('type', self::TYPE)->where('period', $period)->where('file_sha256', $hash)->where('status', 'completed')->exists();
    }

    private function assignmentTable(): string
    {
        return (string) config('analytics.assignment_table');
    }

    /** @param array<int, mixed> $values */
    private function isEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->stringValue($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stringValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : trim((string) $value);
    }

    private function headerKey(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', '', trim($value)));
    }

    private function isAllowedMime(?string $mime): bool
    {
        $allowed = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

        return in_array($mime, $allowed, true);
    }

    /** @param array<string, mixed> $replacement
     * @return array<string, mixed>
     */
    private function metadata(AnalyticsImport $import, array $replacement): array
    {
        return array_replace_recursive((array) $import->metadata, $replacement);
    }

    /** @return array<string, mixed> */
    private function validationMetadata(AnalyticsImport $import): array
    {
        return (array) (($import->metadata ?? [])['validation'] ?? []);
    }

    /** @return array<string, mixed> */
    private function metricsMetadata(AnalyticsImport $import): array
    {
        return (array) (($import->metadata ?? [])['metrics'] ?? []);
    }

    private function milliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function peakMemoryMb(): float
    {
        return round(memory_get_peak_usage(true) / 1024 / 1024, 2);
    }
}
