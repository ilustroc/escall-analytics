<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Writes rejected source rows without keeping the error file in memory. */
final class ImportErrorCsvWriter
{
    /** @var resource */
    private $stream;

    private int $count = 0;

    /** @param list<string> $headers */
    private function __construct(
        private readonly string $storedFilename,
        private readonly string $downloadFilename,
        private readonly array $headers,
    ) {
        $directory = dirname($this->storedFilename);
        Storage::disk('local')->makeDirectory($directory);
        $this->stream = fopen(Storage::disk('local')->path($this->storedFilename), 'w+b');
        if ($this->stream === false) {
            throw new RuntimeException('No se pudo generar el archivo de errores.');
        }

        fwrite($this->stream, "\xEF\xBB\xBF");
        fputcsv($this->stream, [...$this->headers, 'FILA_ORIGEN', 'CAMPO_ERROR', 'MOTIVO_ERROR'], ';');
    }

    /** @param list<string> $headers */
    public static function create(int $importId, string $type, array $headers): self
    {
        $filename = 'imports/errors/'.Str::uuid().'.csv';
        $downloadPrefix = $type === PaymentImportService::TYPE ? 'errores-pagos-expertis' : 'errores-asignacion-expertis';

        return new self($filename, "{$downloadPrefix}-{$importId}.csv", $headers);
    }

    /** @param array<string, mixed> $values */
    public function append(int $rowNumber, array $values, string $field, string $reason): void
    {
        $row = [];
        foreach ($this->headers as $header) {
            $row[] = (string) ($values[$header] ?? '');
        }
        fputcsv($this->stream, [...$row, $rowNumber, $field, $reason], ';');
        $this->count++;
    }

    /** @return array{storedFilename: string, downloadFilename: string}|null */
    public function close(): ?array
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        if ($this->count === 0) {
            Storage::disk('local')->delete($this->storedFilename);

            return null;
        }

        return ['storedFilename' => $this->storedFilename, 'downloadFilename' => $this->downloadFilename];
    }
}
