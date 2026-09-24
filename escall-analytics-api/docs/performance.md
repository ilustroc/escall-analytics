# Consideraciones de rendimiento

Los dashboards Expertis y Operación diaria consultan directamente las tablas fuente: `gestiones`, `Asignacion_Expertis_Historico` y `tipificaciones_expertis`. No dependen de VIEWs para su ejecución.

Los filtros por fecha usan el rango indexable `fecha_gestion >= fechaDesde` y `fecha_gestion < fechaHastaExclusiva`. Las métricas se restringen al catálogo oficial de tipificaciones antes de consolidar el mejor resultado por DNI con `ROW_NUMBER()`.

La asignación se consulta solamente cuando una cosecha filtra el universo mediante `EXISTS`, o cuando se enriquecen las filas ya paginadas del detalle. Esto evita multiplicar gestiones por una relación multicuenta.

## Columnas normalizadas

Las columnas generadas STORED `gestiones.dni_normalizado` y `Asignacion_Expertis_Historico.documento_normalizado` preservan los DNI numéricos de menos de ocho caracteres con ceros iniciales. Sus índices permiten comparar asignaciones sin ejecutar `TRIM`, `CAST` o `LPAD` para cada fila del dashboard.

## Cache de respuestas

Con `CACHE_STORE=file`, la API guarda únicamente respuestas agregadas:

- `expertis/filtros`: 10 minutos.
- `expertis/dashboard`: 60 segundos por combinación normalizada de filtros.
- `operativo/dashboard`: 60 segundos por combinación normalizada de filtros.

El detalle paginado no se cachea. La clave ordena las selecciones múltiples para que el mismo filtro use una sola entrada. Para invalidar las respuestas agregadas sin borrar el resto de la caché de Laravel:

```powershell
php artisan analytics:cache:invalidate
```
