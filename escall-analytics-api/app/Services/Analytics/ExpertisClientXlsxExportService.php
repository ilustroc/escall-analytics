<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use RuntimeException;
use XMLWriter;
use ZipArchive;

class ExpertisClientXlsxExportService
{
    /** @var list<string> */
    private const HEADERS = ['CARTERA', 'DNI', 'TITULAR', 'CODIGO', 'ENTIDAD', 'COSECHA', 'PRODUCTO', 'SUB_PRODUCTO', 'RANGO', 'DEUDA TOTAL', 'DEUDA CAPITAL', 'CAMPAÑA', 'TIPIFICACION DE MEJOR GESTION - MES PASADO', 'MC - MEJOR GESTION', 'FECHA DE ULTIMA GESTION', 'PAGO', 'TELEFONO - ULTIMA GESTION', 'STATUS - ULTIMA GESTION', 'TIPIFICACION - ULTIMA GESTION', 'OBSERVACION - ULTIMA GESTION', 'TELEFONO - MEJOR GESTION', 'STATUS - MEJOR GESTION', 'TIPIFICACION - MEJOR GESTION', 'OBSERVACION - MEJOR GESTION', 'FECHA - MEJOR GESTION', 'INTENSIDAD'];

    public function __construct(private readonly ExpertisClientReportQueryService $query) {}

    /** @return array{path:string,filename:string,rows:int,range:string,subject:string,periodLabel:string} */
    public function createPrivateReport(CarbonImmutable $date): array
    {
        $filename = 'Reporte_Cartera_Expertis_'.$date->format('Ymd').'.xlsx';
        $path = 'reports/expertis/'.$filename;
        $disk = storage_path('app/private');
        if (! is_dir($disk.'/reports/expertis') && ! mkdir($disk.'/reports/expertis', 0755, true) && ! is_dir($disk.'/reports/expertis')) {
            throw new RuntimeException('No se pudo crear el directorio temporal.');
        }
        $output = $disk.'/'.$path;
        if (! copy(resource_path('report-templates/expertis_client_report_template.xlsx'), $output)) {
            throw new RuntimeException('No se pudo copiar la plantilla Excel.');
        }
        $rows = $this->writeSheet($output, $date);
        $range = 'Sheet1!$A$1:$Z$'.($rows + 1);

        return ['path' => $path, 'filename' => $filename, 'rows' => $rows, 'range' => $range, 'subject' => 'Reporte de Cartera – Expertis | '.$date->format('d').' de '.$this->month($date).' de '.$date->year, 'periodLabel' => $date->format('d').' de '.$this->month($date).' de '.$date->year];
    }

    public function delete(string $path): void
    {
        @unlink(storage_path('app/private/'.$path));
    }

    private function writeSheet(string $file, CarbonImmutable $date): int
    {
        $temp = tempnam(sys_get_temp_dir(), 'expertis-sheet-');
        if ($temp === false) {
            throw new RuntimeException('No se pudo crear XML temporal.');
        }
        $xml = new XMLWriter;
        $xml->openUri($temp);
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->startElement('sheetData');
        $this->row($xml, 1, self::HEADERS, true);
        $count = 0;
        foreach ($this->query->cursor($date) as $record) {
            $count++;
            $this->row($xml, $count + 1, [
                $record->cartera, $record->dni, $record->titular, $record->codigo, $record->entidad, $record->cosecha, $record->producto, $record->sub_producto, $record->rango,
                $record->deuda_total, $record->deuda_capital, $record->campana, $record->tipificacion_mes_pasado ?? 'SIN GESTION', $record->mc_mejor_gestion,
                $record->fecha_ultima_gestion, $record->pago, $record->telefono_ultima_gestion, $record->status_ultima_gestion, $record->tipificacion_ultima_gestion, $record->observacion_ultima_gestion,
                $record->telefono_mejor_gestion, $record->status_mejor_gestion, $record->tipificacion_mejor_gestion, $record->observacion_mejor_gestion, $record->fecha_mejor_gestion, $record->intensidad,
            ]);
        }
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
        $zip = new ZipArchive;
        if ($zip->open($file) !== true) {
            throw new RuntimeException('No se pudo abrir la plantilla Excel.');
        }
        try {
            $sheetPath = $this->sheetPath($zip);
            $sheet = $zip->getFromName($sheetPath);
            if ($sheet === false) {
                throw new RuntimeException('No se encontró Sheet1.');
            }
            $last = $count + 1;
            $data = file_get_contents($temp);
            if ($data === false) {
                throw new RuntimeException('No se pudo leer XML temporal.');
            }
            $data = preg_replace('/<\?xml[^>]+\?>/', '', $data) ?: $data;
            $sheet = preg_replace('/<dimension ref="[^"]+"\/>/', '<dimension ref="A1:Z'.$last.'"/>', $sheet) ?: $sheet;
            $sheet = preg_replace('/<sheetData>.*?<\/sheetData>/', $data, $sheet) ?: $sheet;
            $sheet = preg_replace('/<autoFilter ref="[^"]+"/', '<autoFilter ref="A1:Z'.$last.'"', $sheet) ?: $sheet;
            $zip->addFromString($sheetPath, $sheet);
            $pivot = $zip->getFromName('xl/pivotCache/pivotCacheDefinition1.xml');
            if ($pivot === false) {
                throw new RuntimeException('No se encontró PivotCache.');
            }
            $pivot = preg_replace('/<pivotCacheDefinition /', '<pivotCacheDefinition refreshOnLoad="1" enableRefresh="1" ', $pivot, 1) ?: $pivot;
            $pivot = preg_replace('/<worksheetSource ref="[^"]+" sheet="Sheet1"/', '<worksheetSource ref="A1:Z'.$last.'" sheet="Sheet1"', $pivot) ?: $pivot;
            $zip->addFromString('xl/pivotCache/pivotCacheDefinition1.xml', $pivot);
        } finally {
            $zip->close();
            @unlink($temp);
        }

        return $count;
    }

    private function row(XMLWriter $xml, int $number, array $values, bool $header = false): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $number);
        $xml->writeAttribute('spans', '1:26');
        foreach ($values as $index => $value) {
            $column = chr(65 + $index);
            $xml->startElement('c');
            $xml->writeAttribute('r', $column.$number);
            $xml->writeAttribute('s', $header ? '1' : '4');
            $numeric = ! $header && in_array($index, [9, 10, 11, 15, 25], true);
            if ($numeric) {
                $xml->writeElement('v', (string) ($value ?? 0));
            } else {
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->writeElement('t', (string) ($value ?? ''));
                $xml->endElement();
            } $xml->endElement();
        }
        $xml->endElement();
    }

    private function sheetPath(ZipArchive $zip): string
    {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        preg_match('/<sheet name="Sheet1"[^>]*r:id="([^"]+)"/', $workbook, $match);
        $relationship = $match[1] ?? '';
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        preg_match('/<Relationship Id="'.preg_quote($relationship, '/').'"[^>]*Target="([^"]+)"/', $rels, $target);

        return 'xl/'.($target[1] ?? 'worksheets/sheet2.xml');
    }

    private function month(CarbonImmutable $date): string
    {
        return [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'][$date->month];
    }
}
