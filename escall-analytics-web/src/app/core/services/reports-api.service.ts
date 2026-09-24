import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { GestionReportFilters, GestionReportOptions, PaymentReportFilters, PaymentReportOptions, ReportGestion, ReportPayment, ReportResponse } from '../models/reports.models';

type Paging = { page: number; perPage: number; sortField: string; sortDirection: 'asc' | 'desc' };

@Injectable({ providedIn: 'root' })
export class ReportsApiService {
  private readonly http = inject(HttpClient);
  private readonly url = `${environment.apiBaseUrl}/reportes`;

  gestiones(filters: GestionReportFilters, paging: Paging): Observable<ReportResponse<ReportGestion>> {
    return this.http.get<ReportResponse<ReportGestion>>(`${this.url}/gestiones`, { params: this.gestionParams(filters, paging) });
  }

  gestionFilters(filters: Pick<GestionReportFilters, 'fechaDesde' | 'fechaHasta'>): Observable<GestionReportOptions> {
    return this.http.get<GestionReportOptions>(`${this.url}/gestiones/filtros`, { params: { fechaDesde: filters.fechaDesde, fechaHasta: filters.fechaHasta } });
  }

  exportGestiones(filters: GestionReportFilters, paging: Paging): Observable<Blob> {
    return this.http.get(`${this.url}/gestiones/exportar`, { params: this.gestionParams(filters, paging), responseType: 'blob' });
  }

  pagos(filters: PaymentReportFilters, paging: Paging): Observable<ReportResponse<ReportPayment>> {
    return this.http.get<ReportResponse<ReportPayment>>(`${this.url}/pagos`, { params: this.paymentParams(filters, paging) });
  }

  paymentFilters(filters: Pick<PaymentReportFilters, 'fechaDesde' | 'fechaHasta'>): Observable<PaymentReportOptions> {
    return this.http.get<PaymentReportOptions>(`${this.url}/pagos/filtros`, { params: { fechaDesde: filters.fechaDesde, fechaHasta: filters.fechaHasta } });
  }

  exportPayments(filters: PaymentReportFilters, paging: Paging): Observable<Blob> {
    return this.http.get(`${this.url}/pagos/exportar`, { params: this.paymentParams(filters, paging), responseType: 'blob' });
  }

  private gestionParams(filters: GestionReportFilters, paging: Paging): HttpParams {
    let params = this.baseParams(filters.fechaDesde, filters.fechaHasta, paging)
      .set('dni', filters.dni).set('telefono', filters.telefono).set('search', filters.search);
    params = this.append(params, 'asesor[]', filters.asesor);
    params = this.append(params, 'cosecha[]', filters.cosecha);
    params = this.append(params, 'cartera[]', filters.cartera);
    params = this.append(params, 'nivel1[]', filters.nivel1);
    return this.append(params, 'nivel2[]', filters.nivel2);
  }

  private paymentParams(filters: PaymentReportFilters, paging: Paging): HttpParams {
    let params = this.baseParams(filters.fechaDesde, filters.fechaHasta, paging)
      .set('codigo', filters.codigo).set('dni', filters.dni).set('ejecutivo', filters.ejecutivo).set('search', filters.search);
    return this.append(params, 'tipoAcuerdo[]', filters.tipoAcuerdo);
  }

  private baseParams(fechaDesde: string, fechaHasta: string, paging: Paging): HttpParams {
    return new HttpParams().set('fechaDesde', fechaDesde).set('fechaHasta', fechaHasta)
      .set('page', paging.page).set('perPage', paging.perPage).set('sortField', paging.sortField).set('sortDirection', paging.sortDirection);
  }

  private append(params: HttpParams, name: string, values: readonly string[]): HttpParams {
    return values.reduce((current, value) => current.append(name, value), params);
  }
}
