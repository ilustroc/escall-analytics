<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class GestionCsvExportService
{
    /** @var list<string> */
    private const HEADERS = [
        'ID Gestion', 'Canal Gestion', 'Canal Asignacion', 'DNI', 'Nombre Cliente', 'Cartera', 'Asesor', 'Equipo', 'Telefono', 'Fecha Llamada',
        'Cosecha', 'Hora', 'Nivel 1', 'Nivel 2', 'Fecha Compromiso', 'Monto', 'Observacion', 'Medio Gestion', 'Puntaje',
    ];

    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function __construct(private readonly ReportQueryService $reports) {}

    /**
     * @return array{path:string,filename:string,rows:int,filters:array<string,mixed>,subject:string,periodLabel:string}
     */
    public function createPrivateReport(CarbonImmutable $date): array
    {
        $filters = $this->automaticFilters($date);
        $filename = $this->filename($date);
        $path = 'reports/expertis/'.$filename;
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));
        $stream = fopen($disk->path($path), 'wb');
        if ($stream === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del reporte Expertis.');
        }

        try {
            $rows = $this->write($stream, $filters, 'fechaLlamada', 'desc');
        } finally {
            fclose($stream);
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'rows' => $rows,
            'filters' => $filters,
            'subject' => $this->subject($date),
            'periodLabel' => $this->periodLabel($date),
        ];
    }

    /** @param resource $stream
     * @param  array<string,mixed>  $filters
     */
    public function write(mixed $stream, array $filters, string $sort, string $direction): int
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::HEADERS, ';');
        $rows = 0;
        foreach ($this->reports->gestionExport($filters, $sort, $direction) as $row) {
            fputcsv($stream, [
                $row->id_gestion, $row->canal_gestion, $row->canal_asignacion, $row->dni, $row->nombre_cliente,
                $row->cartera, $row->asesor, $row->equipo, $row->telefono, $row->fecha_llamada, $row->cosecha,
                $row->hora, $row->nivel1, $row->nivel2, $row->fecha_compromiso, $row->monto, $row->observacion,
                $row->medio_gestion, $row->puntaje,
            ], ';');
            $rows++;
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function automaticFilters(CarbonImmutable $date): array
    {
        return [
            'fechaDesde' => $date->startOfMonth()->toDateString(),
            'fechaHasta' => $date->toDateString(),
            'asesor' => [], 'cosecha' => [], 'nivel1' => [], 'nivel2' => [],
            'cartera' => [], 'dni' => '', 'telefono' => '', 'search' => '',
        ];
    }

    public function filename(CarbonImmutable $date): string
    {
        return sprintf('Reporte_Gestiones_Expertis_%s_%s.csv', $date->startOfMonth()->format('Ymd'), $date->format('Ymd'));
    }

    public function subject(CarbonImmutable $date): string
    {
        return 'Reporte de Gestiones – Expertis | '.$this->periodLabel($date);
    }

    public function periodLabel(CarbonImmutable $date): string
    {
        return sprintf('01 al %02d de %s de %d', $date->day, self::MONTHS[$date->month], $date->year);
    }

    public function delete(string $path): void
    {
        Storage::disk('local')->delete($path);
    }
}
