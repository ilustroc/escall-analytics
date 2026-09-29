<?php

use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ExpertisAssignmentImportController;
use App\Http\Controllers\Api\V1\ExpertisDashboardController;
use App\Http\Controllers\Api\V1\ExpertisFilterController;
use App\Http\Controllers\Api\V1\ExpertisGestionController;
use App\Http\Controllers\Api\V1\ExpertisPaymentImportController;
use App\Http\Controllers\Api\V1\OperativoDashboardController;
use App\Http\Controllers\Api\V1\ReportGestionController;
use App\Http\Controllers\Api\V1\ReportPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('security.headers')->group(function (): void {
    Route::get('health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'ESCALL Analytics API',
    ]));

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:analytics-login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        Route::middleware('analytics.password-changed')->group(function (): void {
            Route::middleware('analytics.role:administrador,supervisor,proveedor')->group(function (): void {
                Route::get('expertis/filtros', ExpertisFilterController::class);
                Route::get('expertis/dashboard', ExpertisDashboardController::class);
                Route::get('expertis/gestiones', ExpertisGestionController::class);
            });

            Route::middleware('analytics.role:administrador,supervisor')->group(function (): void {
                Route::get('operativo/dashboard', OperativoDashboardController::class);
                Route::get('reportes/gestiones', [ReportGestionController::class, 'index']);
                Route::get('reportes/gestiones/filtros', [ReportGestionController::class, 'filters']);
                Route::get('reportes/gestiones/exportar', [ReportGestionController::class, 'export']);
                Route::get('reportes/pagos', [ReportPaymentController::class, 'index']);
                Route::get('reportes/pagos/filtros', [ReportPaymentController::class, 'filters']);
                Route::get('reportes/pagos/exportar', [ReportPaymentController::class, 'export']);
                Route::get('imports/config', [ExpertisAssignmentImportController::class, 'configuration']);
                Route::get('imports/pagos/config', [ExpertisPaymentImportController::class, 'configuration']);
                Route::get('imports/{import}', [ExpertisAssignmentImportController::class, 'show']);
                Route::get('imports/{import}/errores', [ExpertisAssignmentImportController::class, 'errors']);
                Route::get('importaciones', [ExpertisAssignmentImportController::class, 'history']);
                Route::get('importaciones/expertis/asignacion/plantilla', [ExpertisAssignmentImportController::class, 'template']);
                Route::post('importaciones/expertis/asignacion/validar', [ExpertisAssignmentImportController::class, 'validateFile']);
                Route::post('importaciones/expertis/asignacion/importar', [ExpertisAssignmentImportController::class, 'import']);
                Route::get('importaciones/expertis/pagos/plantilla', [ExpertisPaymentImportController::class, 'template']);
                Route::post('importaciones/expertis/pagos/validar', [ExpertisPaymentImportController::class, 'validateFile']);
                Route::post('importaciones/expertis/pagos/importar', [ExpertisPaymentImportController::class, 'import']);
            });

            Route::middleware('analytics.role:administrador')->group(function (): void {
                Route::get('admin/users', [AdminUserController::class, 'index']);
                Route::post('admin/users', [AdminUserController::class, 'store']);
                Route::patch('admin/users/{user}/status', [AdminUserController::class, 'updateStatus']);
                Route::post('admin/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
            });
        });
    });
});
