# ESCALL Analytics API

API REST para ESCALL Analytics. Los dashboards consultan directamente en modo lectura `gestiones`, `Asignacion_Expertis_Historico` y `tipificaciones_expertis` dentro de `escarperu_software`. Las importaciones solo administran la tabla histórica oficial de asignación mediante un staging independiente.

## Stack

- PHP 8.4+
- Laravel 13
- MariaDB/MySQL existente
- OpenSpout 5 para lectura streaming de XLSX y CSV

## Instalación

```powershell
cd C:\GitHub\escall-analytics\escall-analytics-api
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan serve
```

Completa en `.env` los valores de `DB_HOST`, `DB_USERNAME` y `DB_PASSWORD`. La configuración incluida usa `DB_CONNECTION=mysql`, `DB_PORT=3306` y `DB_DATABASE=escarperu_software`.

La API no modifica registros operativos. Una migración añade columnas generadas de DNI normalizado e índices de lectura a `gestiones` y `Asignacion_Expertis_Historico`, para evitar normalización por fila durante las consultas. Las migraciones propias también crean únicamente tablas `analytics_*` y los campos de seguimiento de `analytics_imports`.

## Endpoints

| Método | Ruta | Descripción |
| --- | --- | --- |
| GET | `/api/v1/health` | Estado del servicio |
| GET | `/api/v1/expertis/filtros` | Catálogos directos de gestión, asignación y tipificaciones |
| GET | `/api/v1/expertis/dashboard` | KPIs y agregados Expertis |
| GET | `/api/v1/expertis/gestiones` | Detalle paginado, búsqueda y orden seguro |
| GET | `/api/v1/operativo/dashboard` | KPIs y agregados diarios por asesor |
| GET | `/api/v1/imports/config` | Límites de carga y registros actuales de un periodo |
| GET | `/api/v1/imports/{id}` | Estado y detalle de una importación |
| GET | `/api/v1/importaciones` | Historial de importaciones |
| POST | `/api/v1/importaciones/expertis/asignacion/validar` | Sube archivo privado y encola validación streaming |
| POST | `/api/v1/importaciones/expertis/asignacion/importar` | Encola el reemplazo atómico de un staging validado |
| GET | `/api/v1/importaciones/expertis/pagos/plantilla` | Descarga una plantilla CSV de pagos sin datos reales |
| POST | `/api/v1/importaciones/expertis/pagos/validar` | Sube y valida pagos CSV/TXT en staging privado |
| POST | `/api/v1/importaciones/expertis/pagos/importar` | Inserta acumulativamente solo los pagos válidos confirmados |

Los filtros de Expertis aceptan `fechaDesde`, `fechaHasta`, `asesor[]`, `cosecha[]`, `nivel1[]` y `nivel2[]`. El detalle también acepta `page`, `perPage` (`25`, `50` o `100`), `search`, `sortField` y `sortDirection`.

Operación diaria acepta `fecha`, `asesor[]`, `equipo[]` y `cosecha[]`; si no recibe fecha usa la fecha actual del servidor.

## Contrato y reglas

Las respuestas públicas usan camelCase. DNI y teléfono se entregan siempre como strings, para preservar ceros iniciales. Los datos nulos de la vista se mantienen como `null`.

La consolidación de cliente se realiza en MySQL con `ROW_NUMBER()` por DNI y el peso centralizado de Nivel 2. La API no descarga toda la cartera a PHP: las métricas, agrupaciones, ranking, intensidad y paginación se calculan en SQL.

Los pagos reales todavía no están integrados, por lo que sus métricas devuelven cero. El monto comprometido usa temporalmente la suma de `Monto` donde `Fecha Compromiso` no es nula; esta regla debe validarse con negocio antes de integrar pagos reales.

## Desarrollo

Angular puede acceder desde `http://localhost:4200`. Configura otros orígenes mediante `CORS_ALLOWED_ORIGINS` en `.env` separados por comas.

```powershell
php artisan route:list
php artisan test
./vendor/bin/pint
php artisan optimize:clear
php artisan analytics:cache:invalidate
```

## Importaciones Expertis

La importación de asignación acepta solamente `.csv` y `.txt`. El archivo se guarda en `storage/app/private/imports`, se valida de forma streaming y se inserta en `analytics_expertis_assignment_staging` en bloques antes de tocar la asignación final.

Una importación pasa por `uploaded`, `validating`, `validated`, `processing`, `completed` o `failed`. La validación detecta encabezados, campos obligatorios y duplicados por `periodo + conca`. Durante la validación el periodo anterior sigue disponible para los dashboards. Solo después de validar staging, una transacción elimina e inserta el periodo completo en `Asignacion_Expertis_Historico`.

La API evita dos importaciones activas del mismo periodo. También rechaza el mismo SHA-256 ya importado exitosamente para el mismo tipo y periodo.

Configura estos límites en `.env`:

```ini
QUEUE_CONNECTION=database
ANALYTICS_IMPORT_MAX_MB=150
ANALYTICS_IMPORT_MAX_ROWS=500000
ANALYTICS_IMPORT_CHUNK_SIZE=1000
ANALYTICS_IMPORT_MAX_UNCOMPRESSED_MB=1024
```

Inicia el worker en desarrollo o bajo el supervisor/cron que corresponda:

```powershell
php artisan queue:work database --queue=imports --sleep=2 --tries=3 --timeout=1800
```

Para cPanel, configura un proceso supervisado si está disponible. Como alternativa, programa un cron por minuto que ejecute un worker con `--stop-when-empty`; el servidor debe tener habilitado PHP CLI. Ajusta `upload_max_filesize` y `post_max_size` por encima de `ANALYTICS_IMPORT_MAX_MB` (por ejemplo 160M para el límite de 150 MB). El procesamiento ocurre en la cola, por lo que `max_execution_time` del request de subida no controla la duración del import.

Limpia staging fallido o cancelado antiguo y sus archivos privados con:

```powershell
php artisan analytics:imports:cleanup --hours=48
```
