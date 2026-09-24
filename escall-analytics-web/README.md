# ESCALL Analytics

Frontend para el análisis y monitoreo de gestión de cobranzas de ESCALL Perú. Los dashboards consumen la API Laravel local; el acceso por perfiles sigue siendo temporalmente simulado en Angular.

## Stack

- Angular 21 con TypeScript estricto y componentes standalone
- Angular Router y Signals
- Tailwind CSS v4
- Apache ECharts
- AG Grid Community
- Lucide Angular (`@lucide/angular`)

## Instalación y ejecución

```bash
npm install
ng serve
```

Abre [http://localhost:4200](http://localhost:4200). También puedes ejecutar `npm start`.

Para consumir datos reales, inicia primero la API:

```powershell
cd C:\GitHub\escall-analytics\escall-analytics-api
php artisan serve --host=127.0.0.1 --port=8000
```

En desarrollo, Angular usa `http://127.0.0.1:8000/api/v1`. La URL se centraliza en `src/environments/environment.ts`; producción queda preparada en `environment.production.ts`.

Para generar el build de producción:

```bash
ng build
```

## Usuarios demo

| Perfil | Correo | Contraseña | Accesos |
| --- | --- | --- | --- |
| Administrador | admin@escallperu.com | 123456 | Dashboard Expertis y Operación diaria |
| Supervisor | supervisor@escallperu.com | 123456 | Dashboard Expertis y Operación diaria |
| Proveedor Expertis | expertis@escallperu.com | 123456 | Dashboard Expertis |

El proveedor es redirigido al Dashboard Expertis si navega manualmente a `/dashboard/operativo`.

## Rutas

- `/login`
- `/dashboard/expertis`
- `/dashboard/operativo` (Administrador y Supervisor)

## Datos y API

`ExpertisApiService` y `OperativoApiService` consumen filtros, dashboards y detalle paginado desde Laravel. Los archivos y servicios mock se conservan temporalmente en `src/app/mock` y `src/app/core/services`, pero ya no participan en la ejecución normal de los dashboards. Los pagos reales continúan pendientes de integración y la API devuelve cero para esas métricas.

Los logos corporativos se encuentran en `public/assets/branding`.
