import { computed, effect, inject, Injectable, OnDestroy, signal } from '@angular/core';
import { finalize, Subscription } from 'rxjs';
import { AnalyticsFilterOptions, ServerPagination } from '../../../core/models/analytics-api.models';
import { AnalyticsFilters, DashboardMetrics, Distribution, GestionExpertis } from '../../../core/models/analytics.models';
import { ExpertisApiService } from '../../../core/services/expertis-api.service';
import { FeedbackService } from '../../../core/services/feedback.service';
import { RefreshService } from '../../../core/services/refresh.service';
import { createExpertisFilters } from '../models/expertis-filters';

const EMPTY_FILTERS: AnalyticsFilterOptions = { asesores: [], equipos: [], cosechas: [], nivel1: [], nivel2: [] };
const EMPTY_METRICS: DashboardMetrics = { gestiones: 0, clientes: 0, contactados: 0, efectivos: 0, contactabilidad: 0, contactoEfectivo: 0, intensidad: 0, compromisos: 0, montoComprometido: 0, clientesPago: 0, montoPagado: 0, cumplimiento: 0, ppc: 0 };
const EMPTY_PAGINATION: ServerPagination = { page: 1, perPage: 25, total: 0, lastPage: 1 };

@Injectable()
export class DashboardExpertisService implements OnDestroy {
  private readonly api = inject(ExpertisApiService);
  private readonly feedback = inject(FeedbackService);
  private readonly refreshControl = inject(RefreshService);
  private refreshTimer?: ReturnType<typeof setTimeout>;
  private filtersRequest?: Subscription;
  private dashboardRequest?: Subscription;
  private tableRequest?: Subscription;
  private dashboardRequestId = 0;
  private tableRequestId = 0;
  private dashboardRequestKey?: string;
  private tableRequestKey?: string;
  private initialTableRequested = false;
  private initialFiltersRequested = false;
  private initialized = false;

  readonly filters = signal(createExpertisFilters());
  readonly filterOptions = signal<AnalyticsFilterOptions>(EMPTY_FILTERS);
  readonly metrics = signal<DashboardMetrics>(EMPTY_METRICS);
  readonly daily = signal<Array<{ fecha: string; gestiones: number; clientes: number }>>([]);
  readonly level1 = signal<Distribution[]>([]);
  readonly paymentCount = signal(0);
  readonly paymentsByAgreement = signal<Array<{ tipo: string; cantidad: number; monto: number }>>([]);
  readonly paymentRanking = signal<Array<{ asesor: string; pagos: number; monto: number }>>([]);
  readonly rows = signal<GestionExpertis[]>([]);
  readonly pagination = signal<ServerPagination>(EMPTY_PAGINATION);
  readonly tableSearch = signal('');
  readonly sort = signal<{ field: string; direction: 'asc' | 'desc' }>({ field: 'fechaLlamada', direction: 'desc' });
  readonly filtersLoading = signal(true);
  readonly dashboardLoading = signal(true);
  readonly dashboardLoaded = signal(false);
  readonly tableLoading = signal(true);
  readonly invalidDates = computed(() => !!this.filters().desde && !!this.filters().hasta && this.filters().desde > this.filters().hasta);
  readonly activeCount = computed(() => Number(this.filters().asesores.length > 0) + Number(this.filters().cosechas.length > 0) + Number(this.filters().nivel1.length > 0) + Number(this.filters().nivel2.length > 0));
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
    this.pagination.update(pagination => ({ ...pagination, page: 1 }));
    this.scheduleRefresh();
  }

  reset(): void {
    if (this.filterKey(this.filters()) === this.filterKey(createExpertisFilters()) && this.pagination().page === 1) return;
    this.filters.set(createExpertisFilters());
    this.pagination.set(EMPTY_PAGINATION);
    this.scheduleRefresh();
  }

  refresh(): void {
    clearTimeout(this.refreshTimer);
    if (this.invalidDates()) return;
    this.loadDashboard(true);
    this.loadTable(true);
  }

  setPage(page: number): void {
    if (page === this.pagination().page) return;
    this.pagination.update(current => ({ ...current, page }));
    this.loadTable();
  }

  setPageSize(perPage: number): void {
    if (perPage === this.pagination().perPage && this.pagination().page === 1) return;
    this.pagination.update(current => ({ ...current, page: 1, perPage }));
    this.loadTable();
  }

  setSearch(search: string): void {
    if (search === this.tableSearch()) return;
    this.tableSearch.set(search);
    this.pagination.update(current => ({ ...current, page: 1 }));
    this.loadTable();
  }

  setSort(sort: { field: string; direction: 'asc' | 'desc' }): void {
    const current = this.sort();
    if (current.field === sort.field && current.direction === sort.direction) return;
    this.sort.set(sort);
    this.pagination.update(current => ({ ...current, page: 1 }));
    this.loadTable();
  }

  private scheduleRefresh(): void {
    clearTimeout(this.refreshTimer);
    this.refreshTimer = setTimeout(() => this.refresh(), 350);
  }

  private loadFilters(): void {
    this.filtersLoading.set(true);
    this.filtersRequest?.unsubscribe();
    this.filtersRequest = this.api.filters().subscribe({
      next: options => { this.filterOptions.set(options); this.filtersLoading.set(false); },
      error: error => {
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
    this.dashboardRequest = this.api.dashboard(this.filters()).pipe(finalize(() => {
      if (requestId === this.dashboardRequestId) this.dashboardLoading.set(false);
    })).subscribe({
      next: response => {
        if (requestId !== this.dashboardRequestId) return;
        this.metrics.set({
          gestiones: response.kpis.gestiones,
          clientes: response.kpis.clientesGestionados,
          contactados: response.kpis.clientesContactados,
          efectivos: response.kpis.clientesContactoEfectivo,
          contactabilidad: response.kpis.contactabilidad,
          contactoEfectivo: response.kpis.contactoEfectivo,
          intensidad: response.kpis.intensidad,
          compromisos: response.kpis.clientesCompromiso,
          montoComprometido: response.kpis.montoComprometido,
          clientesPago: response.pagos.clientes,
          montoPagado: response.pagos.monto,
          cumplimiento: response.pagos.cumplimiento,
          ppc: 0,
        });
        this.daily.set(response.evolucionDiaria);
        const nivel1 = new Map(response.nivel1.map(row => [row.nivel, row.clientes]));
        this.level1.set(['NO CONTACTO', 'CONTACTO EFECTIVO', 'CONTACTO NO EFECTIVO'].map(name => ({ name, value: nivel1.get(name) ?? 0 })));
        this.paymentCount.set(response.pagos.cantidadPagos ?? 0);
        this.paymentsByAgreement.set(response.pagosPorTipo ?? []);
        this.paymentRanking.set(response.rankingPagos ?? []);
        this.dashboardLoaded.set(true);
        this.requestInitialTable();
        this.refreshControl.complete();
      },
      error: error => {
        if (requestId === this.dashboardRequestId) this.fail(error, 'No se pudo obtener la información. Intenta nuevamente.');
      },
    });
  }

  private loadTable(force = false): void {
    const key = this.tableKey();
    if (!force && key === this.tableRequestKey) return;
    const requestId = ++this.tableRequestId;
    this.tableRequestKey = key;
    this.tableRequest?.unsubscribe();
    this.tableLoading.set(true);
    const pagination = this.pagination();
    const sort = this.sort();
    this.tableRequest = this.api.gestiones(this.filters(), { page: pagination.page, perPage: pagination.perPage, search: this.tableSearch(), sortField: sort.field, sortDirection: sort.direction }).pipe(finalize(() => {
      if (requestId === this.tableRequestId) this.tableLoading.set(false);
    })).subscribe({
      next: response => {
        if (requestId !== this.tableRequestId) return;
        this.rows.set(response.data);
        this.pagination.set(response.pagination);
        this.requestInitialFilters();
      },
      error: error => {
        if (requestId === this.tableRequestId) {
          this.requestInitialFilters();
          this.fail(error, 'No se pudo cargar el detalle de gestiones. Intenta nuevamente.');
        }
      },
    });
  }

  ngOnDestroy(): void {
    clearTimeout(this.refreshTimer);
    this.filtersRequest?.unsubscribe();
    this.dashboardRequest?.unsubscribe();
    this.tableRequest?.unsubscribe();
  }

  private filterKey(filters: AnalyticsFilters): string {
    return JSON.stringify({
      fechaDesde: filters.desde,
      fechaHasta: filters.hasta,
      asesor: [...filters.asesores].sort(),
      cosecha: [...filters.cosechas].sort(),
      nivel1: [...filters.nivel1].sort(),
      nivel2: [...filters.nivel2].sort(),
    });
  }

  private tableKey(): string {
    const pagination = this.pagination();
    const sort = this.sort();
    return JSON.stringify({
      filters: this.filterKey(this.filters()),
      page: pagination.page,
      perPage: pagination.perPage,
      search: this.tableSearch().trim(),
      sort,
    });
  }

  private requestInitialTable(): void {
    if (this.initialTableRequested) return;
    this.initialTableRequested = true;
    this.loadTable();
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
