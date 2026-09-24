import { computed, Injectable, signal } from '@angular/core';
import { catchError, map, Observable, of, tap } from 'rxjs';
import { AnalyticsUser, initials, UserRole } from '../models/user.model';
import { AuthApiService } from './auth-api.service';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly current = signal<AnalyticsUser | null>(null);
  private readonly initialized = signal(false);
  readonly user = this.current.asReadonly();
  readonly ready = this.initialized.asReadonly();
  readonly canViewOperation = computed(() => this.hasRole('administrador', 'supervisor'));
  readonly canManageUsers = computed(() => this.hasRole('administrador'));
  readonly canManageImports = computed(() => this.hasRole('administrador', 'supervisor'));

  constructor(private readonly api: AuthApiService) {}

  restore(): Observable<AnalyticsUser | null> {
    return this.api.me().pipe(
      map(response => response.user),
      tap(user => { this.current.set(user); this.initialized.set(true); }),
      catchError(() => { this.current.set(null); this.initialized.set(true); return of(null); }),
    );
  }

  login(email: string, password: string): Observable<AnalyticsUser> {
    return this.api.login(email.trim(), password).pipe(tap(response => this.current.set(response.user)), map(response => response.user));
  }

  changePassword(password: string, passwordConfirmation: string): Observable<AnalyticsUser> {
    return this.api.changePassword(password, passwordConfirmation).pipe(tap(response => this.current.set(response.user)), map(response => response.user));
  }

  logout(): Observable<unknown> {
    return this.api.logout().pipe(tap(() => this.clear()), catchError(() => { this.clear(); return of(null); }));
  }

  clear(): void { this.current.set(null); this.initialized.set(true); }
  hasRole(...roles: UserRole[]): boolean { const role = this.current()?.role; return role !== undefined && roles.includes(role); }
  displayName(): string { return this.current()?.name ?? ''; }
  displayInitials(): string { return this.current() ? initials(this.current()!.name) : ''; }
}
