import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AuthService } from '../services/auth.service';

export const apiCredentialsInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith(environment.apiOrigin)) return next(request);
  const auth = inject(AuthService);
  const router = inject(Router);
  const xsrfToken = request.method === 'GET' || request.method === 'HEAD' ? null : readCookie('XSRF-TOKEN');
  const authenticatedRequest = request.clone({
    withCredentials: true,
    setHeaders: xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {},
  });

  return next(authenticatedRequest).pipe(catchError((error: HttpErrorResponse) => {
    const isAuthEndpoint = request.url.includes('/auth/login') || request.url.includes('/auth/me') || request.url.includes('/sanctum/csrf-cookie');
    if (error.status === 401 && !isAuthEndpoint) {
      auth.clear();
      void router.navigateByUrl('/login');
    }
    if (error.status === 403 && error.error?.mustChangePassword) void router.navigateByUrl('/cambiar-contrasena');
    return throwError(() => error);
  }));
};

const readCookie = (name: string): string | null => {
  const prefix = `${encodeURIComponent(name)}=`;
  const value = document.cookie.split('; ').find(cookie => cookie.startsWith(prefix))?.slice(prefix.length);
  return value ? decodeURIComponent(value) : null;
};
