# Índices de consultas directas

## Índices existentes aprovechados

| Tabla | Índice | Uso |
| --- | --- | --- |
| `gestiones` | `gestiones_tipificacion_index` | Restringe al catálogo oficial de tipificaciones. |
| `gestiones` | `idx_gestiones_dni_normalizado` | Consolidación y detalle por DNI normalizado. |
| `Asignacion_Expertis_Historico` | `idx_aeh_periodo_documento_normalizado` | `EXISTS` por período y DNI, y enriquecimiento del detalle. |
| `Asignacion_Expertis_Historico` | `idx_aeh_periodo_cosecha` | Catálogo y filtro de cosecha. |

## Decisión sobre fecha

El diagnóstico encontró que `gestiones` no tiene un índice con `fecha_gestion` como primer campo. Para el período de control, MariaDB usa `gestiones_tipificacion_index` como rango y no realiza un escaneo `ALL`. Por esa evidencia no se creó un índice de fecha adicional en esta fase.

Si el volumen crece y un nuevo `EXPLAIN` muestra acceso `ALL` o un rango demasiado amplio por tipificación, evaluar con el DBA:

```sql
CREATE INDEX idx_gestiones_fecha_gestion ON gestiones (fecha_gestion);
```

Medir antes y después en un entorno controlado; no duplicar un índice equivalente.

## Búsquedas y ordenamiento por asignación

La búsqueda por nombre de cliente y el ordenamiento por nombre/cosecha requieren una subconsulta correlacionada contra la asignación del período. No multiplican las filas de `gestiones`, pero son más costosos que ordenar columnas propias de gestión. Para el detalle habitual se pagina primero y el enriquecimiento se limita a los DNI de la página.
