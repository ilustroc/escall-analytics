import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { OperativoDashboardResponse } from '../models/analytics-api.models';
import { AnalyticsFilters } from '../models/analytics.models';
import { AnalyticsApiService } from './analytics-api.service';

@Injectable({ providedIn: 'root' })
export class OperativoApiService {
  private readonly http = inject(HttpClient);
  private readonly query = inject(AnalyticsApiService);

  dashboard(filters: AnalyticsFilters): Observable<OperativoDashboardResponse> {
    return this.http.get<OperativoDashboardResponse>(`${environment.apiBaseUrl}/operativo/dashboard`, { params: this.query.operativoParams(filters) });
  }
}
