import { HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { AnalyticsFilters } from '../models/analytics.models';

@Injectable({ providedIn: 'root' })
export class AnalyticsApiService {
  params(filters: Partial<AnalyticsFilters>): HttpParams {
    let params = new HttpParams();
    if (filters.desde) params = params.set('fechaDesde', filters.desde);
    if (filters.hasta) params = params.set('fechaHasta', filters.hasta);
    params = this.appendMany(params, 'asesor[]', filters.asesores);
    params = this.appendMany(params, 'cosecha[]', filters.cosechas);
    params = this.appendMany(params, 'nivel1[]', filters.nivel1);
    params = this.appendMany(params, 'nivel2[]', filters.nivel2);
    return this.appendMany(params, 'equipo[]', filters.equipos);
  }

  operativoParams(filters: Partial<AnalyticsFilters>): HttpParams {
    let params = new HttpParams();
    if (filters.desde) params = params.set('fecha', filters.desde);
    params = this.appendMany(params, 'asesor[]', filters.asesores);
    params = this.appendMany(params, 'equipo[]', filters.equipos);
    return this.appendMany(params, 'cosecha[]', filters.cosechas);
  }

  appendMany(params: HttpParams, key: string, values: readonly string[] | undefined): HttpParams {
    return (values ?? []).reduce((current, value) => current.append(key, value), params);
  }
}
