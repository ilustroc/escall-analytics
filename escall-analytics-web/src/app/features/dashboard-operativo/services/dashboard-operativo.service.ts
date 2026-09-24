import { computed, effect, inject, Injectable, OnDestroy, signal } from '@angular/core';
import { finalize, Subscription } from 'rxjs';
import { AnalyticsFilterOptions } from '../../../core/models/analytics-api.models';
import {
  AdvisorEffective,
  AdvisorPpc,
  AdvisorRanking,
  AnalyticsFilters,
  DashboardMetrics,
  HourlyActivity,
  RecentActivity,
} from '../../../core/models/analytics.models';
import { ExpertisApiService } from '../../../core/services/expertis-api.service';
import { FeedbackService } from '../../../core/services/feedback.service';
import { OperativoApiService } from '../../../core/services/operativo-api.service';
import { RefreshService } from '../../../core/services/refresh.service';
import { todayLocalIsoDate } from '../../../core/utils/local-date';
import { createOperativoFilters } from '../models/operativo-filters';

const EMPTY_FILTERS: AnalyticsFilterOptions = {
  asesores: [],
  equipos: [],
  cosechas: [],
  nivel1: [],
  nivel2: [],
};
const EMPTY_METRICS: DashboardMetrics = {
  gestiones: 0,
  clientes: 0,
  contactados: 0,
  efectivos: 0,
  contactabilidad: 0,
  contactoEfectivo: 0,
  intensidad: 0,
  compromisos: 0,
  montoComprometido: 0,
  clientesPago: 0,
  montoPagado: 0,
  cumplimiento: 0,
  ppc: 0,
};

@Injectable()
export class DashboardOperativoService implements OnDestroy {
  private readonly api = inject(OperativoApiService);
  private readonly expertisApi = inject(ExpertisApiService);
  private readonly feedback = inject(FeedbackService);
  private readonly refreshControl = inject(RefreshService);
  private refreshTimer?: ReturnType<typeof setTimeout>;
  private filtersRequest?: Subscription;
  private dashboardRequest?: Subscription;
  private dashboardRequestId = 0;
  private dashboardRequestKey?: string;
  private initialFiltersRequested = false;
  private initialized = false;

  readonly filters = signal(createOperativoFilters());
  readonly filterOptions = signal<AnalyticsFilterOptions>(EMPTY_FILTERS);
  readonly metrics = signal<DashboardMetrics>(EMPTY_METRICS);
  readonly ranking = signal<AdvisorRanking[]>([]);
  readonly hourly = signal<HourlyActivity[]>([]);
  readonly effective = signal<AdvisorEffective[]>([]);
  readonly ppcByAdvisor = signal<AdvisorPpc[]>([]);
  readonly recent = signal<RecentActivity[]>([]);
  readonly filtersLoading = signal(true);
  readonly dashboardLoading = signal(true);
  readonly dashboardLoaded = signal(false);
  readonly hasResults = computed(() => this.metrics().gestiones > 0);

  constructor() {
    this.loadDashboard();
    effect(() => {
      this.refreshControl.revision();
      if (this.initialized) this.refresh();
      this.initialized = true;
    });
  }

  patch(value: Partial<AnalyticsFilters>): void {
    const next = { ...this.filters(), ...value };
    if (this.filterKey(next) === this.filterKey(this.filters())) return;
    this.filters.set(next);
    this.scheduleRefresh();
  }

  setDate(date: string): void {
    const selectedDate = date || todayLocalIsoDate();
    this.patch({ desde: selectedDate, hasta: selectedDate });
  }

  reset(): void {
    if (this.filterKey(this.filters()) === this.filterKey(createOperativoFilters())) return;
    this.filters.set(createOperativoFilters());
    this.scheduleRefresh();
  }

  refresh(): void {
    clearTimeout(this.refreshTimer);
    this.loadDashboard(true);
  }

  private scheduleRefresh(): void {
    clearTimeout(this.refreshTimer);
    this.refreshTimer = setTimeout(() => this.refresh(), 350);
  }

  private loadFilters(): void {
    this.filtersLoading.set(true);
    this.filtersRequest?.unsubscribe();
    this.filtersRequest = this.expertisApi.filters().subscribe({
      next: (options) => {
        this.filterOptions.set(options);
        this.filtersLoading.set(false);
      },
      error: (error) => {
        this.filtersLoading.set(false);
        this.fail(error, 'No se pudieron cargar los filtros.');
      },
    });
  }

  private loadDashboard(force = false): void {
    const key = this.filterKey(this.filters());
    if (!force && key === this.dashboardRequestKey) return;
    const requestId = ++this.dashboardRequestId;
    this.dashboardRequestKey = key;
    this.dashboardRequest?.unsubscribe();
    this.dashboardLoading.set(true);
    this.dashboardRequest = this.api
      .dashboard(this.filters())
      .pipe(
        finalize(() => {
          if (requestId === this.dashboardRequestId) this.dashboardLoading.set(false);
        }),
      )
      .subscribe({
        next: (response) => {
          if (requestId !== this.dashboardRequestId) return;
          this.metrics.set({
            gestiones: response.kpis.gestiones,
            clientes: response.kpis.clientesGestionados,
            contactados: response.kpis.clientesContactados,
            efectivos: response.kpis.contactoEfectivo,
            contactabilidad: 0,
            contactoEfectivo: response.kpis.contactoEfectivoPorcentaje,
            intensidad: 0,
            compromisos: 0,
            montoComprometido: response.kpis.montoComprometido,
            clientesPago: response.kpis.clientesConPago,
            montoPagado: response.kpis.montoPagado,
            cumplimiento: 0,
            ppc: response.kpis.ppc,
          });
          this.ranking.set(
            response.rankingAsesores.map((row, index) => ({
              asesor: row.asesor,
              posicion: index + 1,
              gestiones: row.gestiones,
              clientes: row.clientes,
              contactados: row.contactados,
              efectivos: row.contactoEfectivo,
              contactoEfectivo: row.contactoEfectivoPorcentaje,
              ppc: row.ppc,
              montoComprometido: row.montoPromesa,
              clientesPago: row.pagos,
              montoPagado: row.montoPago,
              intensidad: row.intensidad,
            })),
          );
          this.hourly.set(
            response.actividadHoraria.map((row) => ({
              hora: row.hora,
              gestiones: row.gestiones,
              contactos: row.contactados,
              efectivos: row.contactoEfectivo,
              ppc: row.ppc,
            })),
          );
          this.effective.set(response.contactoEfectivoAsesor);
          this.ppcByAdvisor.set(response.ppcAsesor);
          this.recent.set(response.actividadReciente);
          this.dashboardLoaded.set(true);
          this.requestInitialFilters();
          this.refreshControl.complete();
        },
        error: (error) => {
          if (requestId === this.dashboardRequestId)
            this.fail(error, 'No se pudo obtener la información. Intenta nuevamente.');
        },
      });
  }

  ngOnDestroy(): void {
    clearTimeout(this.refreshTimer);
    this.filtersRequest?.unsubscribe();
    this.dashboardRequest?.unsubscribe();
  }

  private filterKey(filters: AnalyticsFilters): string {
    return JSON.stringify({
      fechaDesde: filters.desde,
      fechaHasta: filters.hasta,
      asesor: [...filters.asesores].sort(),
      equipo: [...filters.equipos].sort(),
      cosecha: [...filters.cosechas].sort(),
    });
  }

  private requestInitialFilters(): void {
    if (this.initialFiltersRequested) return;
    this.initialFiltersRequested = true;
    this.loadFilters();
  }

  private fail(error: unknown, message: string): void {
    console.error('Analytics API request failed', error);
    this.refreshControl.complete();
    this.feedback.show(message);
  }
}
