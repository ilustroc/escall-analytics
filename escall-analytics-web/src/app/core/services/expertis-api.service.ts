import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AnalyticsFilterOptions, ExpertisDashboardResponse, ExpertisGestionesResponse } from '../models/analytics-api.models';
import { AnalyticsFilters } from '../models/analytics.models';
import { AnalyticsApiService } from './analytics-api.service';

@Injectable({ providedIn: 'root' })
export class ExpertisApiService {
  private readonly http = inject(HttpClient);
  private readonly query = inject(AnalyticsApiService);
  private readonly url = `${environment.apiBaseUrl}/expertis`;

  filters(): Observable<AnalyticsFilterOptions> {
    return this.http.get<AnalyticsFilterOptions>(`${this.url}/filtros`);
  }

  dashboard(filters: AnalyticsFilters): Observable<ExpertisDashboardResponse> {
    return this.http.get<ExpertisDashboardResponse>(`${this.url}/dashboard`, { params: this.query.params(filters) });
  }

  gestiones(filters: AnalyticsFilters, options: { page: number; perPage: number; search: string; sortField: string; sortDirection: 'asc' | 'desc' }): Observable<ExpertisGestionesResponse> {
    let params: HttpParams = this.query.params(filters)
      .set('page', options.page)
      .set('perPage', options.perPage)
      .set('sortField', options.sortField)
      .set('sortDirection', options.sortDirection);
    if (options.search.trim()) params = params.set('search', options.search.trim());
    return this.http.get<ExpertisGestionesResponse>(`${this.url}/gestiones`, { params });
  }
}
