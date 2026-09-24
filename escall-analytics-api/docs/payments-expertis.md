# Pagos Expertis

La fuente final es `pagos_expertis`. No se utiliza una VIEW ni una tabla analítica de pagos.

## Archivo y validación

Solo se aceptan CSV y TXT delimitados por `;`, `,`, `|` o tabulador. El lector trabaja con `fopen` y `fgetcsv` por lotes; no carga el archivo completo en memoria. Los campos requeridos son `FECHA_PAGO`, `CODIGO`, `MONTO`, `EJECUTIVO` y `TIPO_ACUERDO`.

Cada fila obtiene `period` desde `FECHA_PAGO` y se valida en lotes contra `Asignacion_Expertis_Historico` mediante `FEC_PRO + conca`. Así, un DNI con varias entidades solo acepta el código completo de la entidad indicada. El DNI se toma de la asignación validada.

Los pagos son acumulativos: la confirmación inserta solamente las filas válidas que no existan, dentro de una transacción. Nunca borra pagos por mes. Los registros rechazados se mantienen únicamente como resultado resumido de la validación y no llegan a `pagos_expertis`.

## Índices revisados

Se aprovechan los índices existentes:

- `Asignacion_Expertis_Historico.idx_aeh_periodo_cuenta (FEC_PRO, conca)` para validar códigos por lote.
- `pagos_expertis.pagos_expertis_fecha_index (fecha)` para los rangos de dashboard.
- `pagos_expertis.pag_exp_cuenta_fecha_index (cuenta_normalizada, fecha)` para coincidencias de cuenta.
- `pagos_expertis.pagos_expertis_hash_fila_unique (hash_fila)` para impedir duplicados lógicos durante la inserción final.

No se creó ningún índice adicional: los existentes cubren el acceso de importación y las consultas directas actuales. Los filtros Nivel 1/Nivel 2 no alteran pagos porque pertenecen a gestión, no a recaudo.

## Worker de cola

```powershell
php artisan queue:work database --queue=imports --sleep=2 --tries=3 --timeout=1800
```

El archivo se guarda de forma privada, la validación se ejecuta en cola y el frontend consulta el estado cada cinco segundos sin superponer solicitudes. Pagos utiliza lotes de 2,000 filas; con sus 16 campos de staging queda por debajo del límite típico de parámetros preparados de MariaDB y reduce consultas remotas frente al lote heredado de 1,200 filas.
