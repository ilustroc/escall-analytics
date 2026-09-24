import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AnalyticsUser, UserRole } from '../models/user.model';

export interface AdminUser extends AnalyticsUser { createdAt: string | null; }
interface UsersResponse { data: AdminUser[]; }
@Injectable({ providedIn: 'root' }) export class AdminUsersApiService {
  private readonly http = inject(HttpClient); private readonly url = `${environment.apiBaseUrl}/admin/users`;
  list(): Observable<UsersResponse> { return this.http.get<UsersResponse>(this.url); }
  create(data: { name: string; email: string; role: Extract<UserRole, 'supervisor' | 'proveedor'> }): Observable<{ user: AdminUser; temporaryPassword: string }> { return this.http.post<{ user: AdminUser; temporaryPassword: string }>(this.url, data); }
  setActive(user: AdminUser, isActive: boolean): Observable<{ user: AdminUser }> { return this.http.patch<{ user: AdminUser }>(`${this.url}/${user.id}/status`, { isActive }); }
  resetPassword(user: AdminUser): Observable<{ temporaryPassword: string }> { return this.http.post<{ temporaryPassword: string }>(`${this.url}/${user.id}/reset-password`, {}); }
}
