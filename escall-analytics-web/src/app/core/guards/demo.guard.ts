import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { UserRole } from '../models/user.model';
import { AuthService } from '../services/auth.service';

const landing = (auth: AuthService): string => auth.user()?.mustChangePassword ? '/cambiar-contrasena' : '/dashboard/expertis';

export const demoGuard: CanActivateFn = () => {
  const auth = inject(AuthService); const router = inject(Router);
  return auth.user() ? (auth.user()!.mustChangePassword ? router.createUrlTree(['/cambiar-contrasena']) : true) : router.createUrlTree(['/login']);
};
export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService); return auth.user() ? inject(Router).createUrlTree([landing(auth)]) : true;
};
export const operationGuard: CanActivateFn = () => inject(AuthService).canViewOperation() ? true : inject(Router).createUrlTree(['/dashboard/expertis']);
export const roleGuard = (...roles: UserRole[]): CanActivateFn => () => inject(AuthService).hasRole(...roles) ? true : inject(Router).createUrlTree(['/dashboard/expertis']);
export const passwordChangeGuard: CanActivateFn = () => inject(AuthService).user() ? true : inject(Router).createUrlTree(['/login']);
