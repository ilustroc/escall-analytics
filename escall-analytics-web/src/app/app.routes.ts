import { Routes } from '@angular/router';
import { demoGuard, guestGuard, operationGuard, passwordChangeGuard, roleGuard } from './core/guards/demo.guard';
export const routes: Routes = [
  {
    path: 'login',
    title: 'Ingresar | ESCALL Analytics',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/login/login').then((m) => m.Login),
  },
  {
    path: 'cambiar-contrasena',
    title: 'Cambiar contraseña | ESCALL Analytics',
    canActivate: [passwordChangeGuard],
    loadComponent: () => import('./features/change-password/change-password').then((m) => m.ChangePassword),
  },
  {
    path: 'dashboard',
    canActivate: [demoGuard],
    canActivateChild: [demoGuard],
    loadComponent: () => import('./layout/app-shell/app-shell').then((m) => m.AppShell),
    children: [
      {
        path: 'expertis',
        title: 'Dashboard Expertis | ESCALL Analytics',
        loadComponent: () =>
          import('./features/dashboard-expertis/pages/dashboard-expertis').then(
            (m) => m.DashboardExpertis,
          ),
      },
      {
        path: 'operativo',
        title: 'Operación diaria | ESCALL Analytics',
        canActivate: [operationGuard],
        loadComponent: () =>
          import('./features/dashboard-operativo/pages/dashboard-operativo').then(
            (m) => m.DashboardOperativo,
          ),
      },
      { path: '', pathMatch: 'full', redirectTo: 'expertis' },
    ],
  },
  {
    path: 'reportes',
    title: 'Reportes | ESCALL Analytics',
    canActivate: [demoGuard, roleGuard('administrador', 'supervisor')],
    loadComponent: () => import('./layout/app-shell/app-shell').then((m) => m.AppShell),
    children: [{ path: '', loadComponent: () => import('./features/reports/reports-page').then((m) => m.ReportsPage) }],
  },
  {
    path: 'importaciones/expertis/asignacion',
    title: 'Importaciones Expertis | ESCALL Analytics',
    canActivate: [demoGuard, roleGuard('administrador', 'supervisor')],
    loadComponent: () => import('./layout/app-shell/app-shell').then((m) => m.AppShell),
    children: [{ path: '', loadComponent: () => import('./features/import-assignment/import-assignment').then((m) => m.ImportAssignment) }],
  },
  {
    path: 'administracion/usuarios',
    title: 'Usuarios | ESCALL Analytics',
    canActivate: [demoGuard, roleGuard('administrador')],
    loadComponent: () => import('./layout/app-shell/app-shell').then((m) => m.AppShell),
    children: [{ path: '', loadComponent: () => import('./features/admin-users/admin-users').then((m) => m.AdminUsers) }],
  },
  { path: '', pathMatch: 'full', redirectTo: 'login' },
  { path: '**', redirectTo: 'login' },
];
