<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

final class ExpertisClientReportQueryService
{
    /** @return Generator<int, object> */
    public function cursor(CarbonImmutable $reportDate): Generator
    {
        $start = $reportDate->startOfMonth()->toDateString();
        $end = $reportDate->addDay()->toDateString();
        $previousStart = $reportDate->subMonth()->startOfMonth()->toDateString();
        $assignmentTable = config('analytics.assignment_table');

        $sql = <<<SQL
WITH asignacion AS (
    SELECT 'EXPERTIS' AS cartera, documento_normalizado AS dni, NombreDeudor AS titular, conca AS codigo,
           CARTERA AS entidad, cosecha, Segmento AS producto, Subsegmento AS sub_producto, RANGO_DEUDA AS rango,
           DT AS deuda_total, DK AS deuda_capital, C1 AS campana
    FROM `{$assignmentTable}`
    WHERE FEC_PRO = ? AND documento_normalizado IS NOT NULL AND documento_normalizado <> ''
), dnis_asignados AS (SELECT DISTINCT dni FROM asignacion),
historial AS (
    SELECT g.id AS id_gestion, g.dni_normalizado AS dni, g.telefono, g.fecha_gestion, g.status, g.tipificacion,
           CASE WHEN g.observacion REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' THEN TRIM(SUBSTR(g.observacion, 20))
                WHEN g.observacion REGEXP '^[0-9]{2}:[0-9]{2}:[0-9]{2}' THEN TRIM(SUBSTR(g.observacion, 9)) ELSE g.observacion END AS observacion,
           COALESCE(t.peso, 999) AS puntaje,
           CASE t.gestion WHEN 'CEF' THEN 'CE' WHEN 'CNE' THEN 'CNE' ELSE 'NC' END AS mc
    FROM gestiones g LEFT JOIN tipificaciones_expertis t ON t.tipificacion = g.tipificacion
    WHERE g.dni_normalizado IN (SELECT dni FROM dnis_asignados)
    UNION ALL
    SELECT l.id, TRIM(l.documento), l.telefono, l.fecha_gestion, l.tipo_gestion, l.resultado, l.observacion,
           CASE UPPER(TRIM(l.resultado)) WHEN 'NO CONTESTA' THEN 15 WHEN 'ABANDONO' THEN 16 WHEN 'BUZON' THEN 17 WHEN 'FALLADO' THEN 18 ELSE 999 END,
           CASE UPPER(TRIM(l.tipo_gestion)) WHEN 'CONTACTO EFECTIVO' THEN 'CE' WHEN 'CONTACTO NO EFECTIVO' THEN 'CNE' ELSE 'NC' END
    FROM llamadas l WHERE TRIM(l.documento) IN (SELECT dni FROM dnis_asignados)
), historial_ranked AS (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY dni ORDER BY fecha_gestion DESC, id_gestion DESC) AS rn_ultima,
           ROW_NUMBER() OVER (PARTITION BY dni ORDER BY puntaje ASC, fecha_gestion DESC, id_gestion DESC) AS rn_mejor
    FROM historial
), mes_pasado_ranked AS (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY dni ORDER BY puntaje ASC, fecha_gestion DESC, id_gestion DESC) AS rn
    FROM historial WHERE fecha_gestion >= ? AND fecha_gestion < ?
), intensidad_mes AS (
    SELECT dni, COUNT(*) AS intensidad FROM historial WHERE fecha_gestion >= ? AND fecha_gestion < ? GROUP BY dni
), pagos_mes AS (
    SELECT cuenta_normalizada AS codigo, SUM(monto) AS pago FROM pagos_expertis WHERE fecha >= ? AND fecha < ? GROUP BY cuenta_normalizada
)
SELECT a.*, mp.tipificacion AS tipificacion_mes_pasado, COALESCE(mp.mc, 'SG') AS mc_mejor_gestion,
       ug.fecha_gestion AS fecha_ultima_gestion, ug.telefono AS telefono_ultima_gestion, COALESCE(ug.status, 'SIN GESTION') AS status_ultima_gestion,
       COALESCE(ug.tipificacion, 'SIN GESTION') AS tipificacion_ultima_gestion, COALESCE(ug.observacion, 'SIN GESTION') AS observacion_ultima_gestion,
       mg.telefono AS telefono_mejor_gestion, COALESCE(mg.status, 'SIN GESTION') AS status_mejor_gestion,
       COALESCE(mg.tipificacion, 'SIN GESTION') AS tipificacion_mejor_gestion, COALESCE(mg.observacion, 'SIN GESTION') AS observacion_mejor_gestion,
       mg.fecha_gestion AS fecha_mejor_gestion, COALESCE(i.intensidad, 0) AS intensidad, COALESCE(p.pago, 0) AS pago
FROM asignacion a
LEFT JOIN historial_ranked ug ON ug.dni = a.dni AND ug.rn_ultima = 1
LEFT JOIN historial_ranked mg ON mg.dni = a.dni AND mg.rn_mejor = 1
LEFT JOIN mes_pasado_ranked mp ON mp.dni = a.dni AND mp.rn = 1
LEFT JOIN intensidad_mes i ON i.dni = a.dni
LEFT JOIN pagos_mes p ON p.codigo = a.codigo
ORDER BY a.dni, a.codigo
SQL;

        yield from DB::cursor($sql, [$reportDate->format('Ym'), $previousStart, $start, $start, $end, $start, $end]);
    }
}
