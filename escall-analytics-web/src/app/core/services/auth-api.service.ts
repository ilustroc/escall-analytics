import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, switchMap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AnalyticsUser } from '../models/user.model';

interface AuthResponse { user: AnalyticsUser; }

@Injectable({ providedIn: 'root' })
export class AuthApiService {
  private readonly http = inject(HttpClient);

  csrfCookie(): Observable<unknown> { return this.http.get(`${environment.apiOrigin}/sanctum/csrf-cookie`); }
  login(email: string, password: string): Observable<AuthResponse> {
    return this.csrfCookie().pipe(switchMap(() => this.http.post<AuthResponse>(`${environment.apiBaseUrl}/auth/login`, { email, password })));
  }
  me(): Observable<AuthResponse> { return this.http.get<AuthResponse>(`${environment.apiBaseUrl}/auth/me`); }
  logout(): Observable<unknown> { return this.http.post(`${environment.apiBaseUrl}/auth/logout`, {}); }
  changePassword(password: string, passwordConfirmation: string): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${environment.apiBaseUrl}/auth/change-password`, { password, password_confirmation: passwordConfirmation });
  }
}
