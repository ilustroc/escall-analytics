import { ChangeDetectionStrategy, Component, OnDestroy, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AgGridAngular } from 'ag-grid-angular';
import { AllCommunityModule, ColDef, ModuleRegistry, SortChangedEvent, themeQuartz } from 'ag-grid-community';
import { finalize, Subscription } from 'rxjs';
import { ServerPagination } from '../../core/models/analytics-api.models';
import { GestionReportFilters, GestionReportOptions, PaymentReportFilters, PaymentReportOptions, ReportGestion, ReportPayment, ReportSummary } from '../../core/models/reports.models';
import { FeedbackService } from '../../core/services/feedback.service';
import { ReportsApiService } from '../../core/services/reports-api.service';
import { currentMonthStartLocalIsoDate, todayLocalIsoDate } from '../../core/utils/local-date';
import { formatDate, formatMoney, formatNumber, formatTime } from '../../core/utils/format';
import { Icon } from '../../shared/components/icon/icon';
import { PageHeader } from '../../shared/components/page-header/page-header';

ModuleRegistry.registerModules([AllCommunityModule]);

const EMPTY_PAGE: ServerPagination = { page: 1, perPage: 25, total: 0, lastPage: 1 };
const EMPTY_GESTION_SUMMARY: ReportSummary = { gestiones: 0, clientes: 0, monto: 0 };
const EMPTY_PAYMENT_SUMMARY: ReportSummary = { pagos: 0, clientes: 0, monto: 0 };

@Component({
  selector: 'app-reports-page',
  imports: [FormsModule, AgGridAngular, Icon, PageHeader],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-page-header title="Reportes" subtitle="Consulta y exportación de gestiones y pagos Expertis." />
    <nav class="report-tabs" aria-label="Tipo de reporte">
      <button type="button" [class.active]="tab() === 'gestiones'" (click)="selectTab('gestiones')"><app-icon name="FileSpreadsheet" [size]="16" />Gestiones</button>
      <button type="button" [class.active]="tab() === 'pagos'" (click)="selectTab('pagos')"><app-icon name="WalletCards" [size]="16" />Pagos</button>
    </nav>

    @if (tab() === 'gestiones') {
      <section class="detail-panel report-filter-panel">
        <header class="detail-header"><h2>Filtros de gestiones</h2><div class="report-actions"><button class="btn btn-ghost" type="button" (click)="clearGestion()">Limpiar filtros</button><button class="btn btn-secondary" type="button" (click)="exportGestiones()" [disabled]="exporting()"><app-icon name="ArrowDownToLine" [size]="15" />Exportar CSV</button><button class="btn btn-primary" type="button" (click)="applyGestiones()"><app-icon name="Search" [size]="15" />Buscar</button></div></header>
        <div class="report-filter-grid">
          <label>Fecha desde<input type="date" [(ngModel)]="gestionForm.fechaDesde" /></label>
          <label>Fecha hasta<input type="date" [(ngModel)]="gestionForm.fechaHasta" /></label>
          <label>DNI<input type="search" [(ngModel)]="gestionForm.dni" placeholder="DNI exacto" /></label>
          <label>Asesor<select [(ngModel)]="gestionForm.asesor"><option value="">Todos</option>@for (item of gestionOptions().asesores; track item) {<option [value]="item">{{ item }}</option>}</select></label>
          <label>Cosecha<select [(ngModel)]="gestionForm.cosecha"><option value="">Todas</option>@for (item of gestionOptions().cosechas; track item) {<option [value]="item">{{ item }}</option>}</select></label>
          <label>Cartera<select [(ngModel)]="gestionForm.cartera"><option value="">Todas</option>@for (item of gestionOptions().carteras; track item) {<option [value]="item">{{ item }}</option>}</select></label>
          <label>Nivel 1<select [(ngModel)]="gestionForm.nivel1"><option value="">Todos</option>@for (item of gestionOptions().nivel1; track item) {<option [value]="item">{{ item }}</option>}</select></label>
          <label>Nivel 2<select [(ngModel)]="gestionForm.nivel2"><option value="">Todos</option>@for (item of gestionOptions().nivel2; track item) {<option [value]="item">{{ item }}</option>}</select></label>
          <label>Teléfono<input type="search" [(ngModel)]="gestionForm.telefono" placeholder="Teléfono" /></label>
          <label class="report-search">Búsqueda general<input type="search" [(ngModel)]="gestionForm.search" (ngModelChange)="queueGestionSearch()" placeholder="DNI, cliente, asesor, teléfono u observación" /></label>
        </div>
      </section>
      <section class="report-summary"><div><span>Gestiones</span><strong>{{ number(gestionSummary().gestiones ?? 0) }}</strong></div><div><span>Clientes únicos</span><strong>{{ number(gestionSummary().clientes) }}</strong></div><div><span>Monto comprometido</span><strong>{{ money(gestionSummary().monto, true) }}</strong></div></section>
      <section class="detail-panel report-grid-panel">
        <header class="detail-header"><h2>Reporte de gestiones</h2><span>{{ pageLabel(gestionPage()) }}</span></header>
        <ag-grid-angular class="report-grid" [theme]="theme" [rowData]="gestionRows()" [columnDefs]="gestionColumns" [defaultColDef]="defaults" [rowHeight]="28" [headerHeight]="34" [loading]="gestionLoading()" [tooltipShowDelay]="250" [enableBrowserTooltips]="true" (sortChanged)="onGestionSort($event)" />
        <footer class="grid-pager"><span>{{ pageLabel(gestionPage()) }}</span><label>Filas<select [value]="gestionPage().perPage" (change)="setGestionSize($event)"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label><div><button class="btn btn-ghost" (click)="setGestionPage(gestionPage().page - 1)" [disabled]="gestionPage().page <= 1">Anterior</button><button class="btn btn-ghost" (click)="setGestionPage(gestionPage().page + 1)" [disabled]="gestionPage().page >= gestionPage().lastPage">Siguiente</button></div></footer>
      </section>
    } @else {
      <section class="detail-panel report-filter-panel">
        <header class="detail-header"><h2>Filtros de pagos</h2><div class="report-actions"><button class="btn btn-ghost" type="button" (click)="clearPayments()">Limpiar filtros</button><button class="btn btn-secondary" type="button" (click)="exportPayments()" [disabled]="exporting()"><app-icon name="ArrowDownToLine" [size]="15" />Exportar CSV</button><button class="btn btn-primary" type="button" (click)="applyPayments()"><app-icon name="Search" [size]="15" />Buscar</button></div></header>
        <div class="report-filter-grid payment-filter-grid">
          <label>Fecha desde<input type="date" [(ngModel)]="paymentForm.fechaDesde" /></label><label>Fecha hasta<input type="date" [(ngModel)]="paymentForm.fechaHasta" /></label><label>Código<input type="search" [(ngModel)]="paymentForm.codigo" placeholder="Código" /></label><label>DNI<input type="search" [(ngModel)]="paymentForm.dni" placeholder="DNI" /></label><label>Ejecutivo<select [(ngModel)]="paymentForm.ejecutivo"><option value="">Todos</option>@for (item of paymentOptions().ejecutivos; track item) {<option [value]="item">{{ item }}</option>}</select></label><label>Tipo de acuerdo<select [(ngModel)]="paymentForm.tipoAcuerdo"><option value="">Todos</option>@for (item of paymentOptions().acuerdos; track item) {<option [value]="item">{{ item }}</option>}</select></label><label class="report-search">Búsqueda general<input type="search" [(ngModel)]="paymentForm.search" (ngModelChange)="queuePaymentSearch()" placeholder="Código, DNI o ejecutivo" /></label>
        </div>
      </section>
      <section class="report-summary"><div><span>Pagos registrados</span><strong>{{ number(paymentSummary().pagos ?? 0) }}</strong></div><div><span>Clientes con pago</span><strong>{{ number(paymentSummary().clientes) }}</strong></div><div><span>Monto pagado</span><strong>{{ money(paymentSummary().monto, true) }}</strong></div></section>
      <section class="detail-panel report-table-panel"><header class="detail-header"><h2>Reporte de pagos</h2><span>{{ pageLabel(paymentPage()) }}</span></header><div class="report-table-wrap"><table class="compact-table report-table"><thead><tr><th>Fecha Pago</th><th>Código</th><th>DNI</th><th class="amount">Monto</th><th>Ejecutivo</th><th>Tipo de Acuerdo</th></tr></thead><tbody>@for (row of paymentRows(); track row.id) {<tr><td>{{ date(row.fechaPago) }}</td><td>{{ row.codigo }}</td><td>{{ row.dni || '—' }}</td><td class="amount">{{ money(row.monto, true) }}</td><td>{{ row.ejecutivo || '—' }}</td><td>{{ row.tipoAcuerdo || '—' }}</td></tr>} @empty {<tr><td colspan="6" class="table-empty">@if (paymentLoading()) { Cargando reporte... } @else { No hay pagos para los filtros seleccionados. }</td></tr>}</tbody></table></div><footer class="grid-pager"><span>{{ pageLabel(paymentPage()) }}</span><label>Filas<select [value]="paymentPage().perPage" (change)="setPaymentSize($event)"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label><div><button class="btn btn-ghost" (click)="setPaymentPage(paymentPage().page - 1)" [disabled]="paymentPage().page <= 1">Anterior</button><button class="btn btn-ghost" (click)="setPaymentPage(paymentPage().page + 1)" [disabled]="paymentPage().page >= paymentPage().lastPage">Siguiente</button></div></footer></section>
    }
  `,
  styles: [`.report-tabs{display:flex;gap:4px;border-bottom:1px solid #dce5ef;margin-bottom:12px}.report-tabs button{display:flex;align-items:center;gap:7px;border:0;border-bottom:2px solid transparent;background:transparent;padding:9px 11px;color:#60738e}.report-tabs button.active{color:#0659bb;border-color:#0a66c2;font-weight:650}.report-filter-panel{margin-bottom:10px}.report-filter-grid{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:9px;padding:10px 12px 13px}.report-filter-grid label{display:grid;gap:4px;font-size:10px;color:#617492}.report-filter-grid input,.report-filter-grid select{height:31px;font-size:11px}.report-search{grid-column:span 2}.report-actions{display:flex;gap:7px;align-items:center}.report-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-bottom:10px}.report-summary>div{border:1px solid #dfe6ee;border-radius:7px;background:#fff;padding:9px 12px;display:grid;gap:3px}.report-summary span{font-size:10px;color:#71829a}.report-summary strong{font-size:17px;color:#123f76}.report-grid-panel,.report-table-panel{overflow:hidden}.report-grid{height:490px;width:100%;display:block}.report-table-wrap{overflow:auto;max-height:490px}.report-table{min-width:800px}.report-table thead{position:sticky;top:0;z-index:1}.report-table th{background:#f6f8fb}.report-table td,.report-table th{white-space:nowrap}.amount{text-align:right!important}.detail-header>span{font-size:10px;color:#71829a}@media(max-width:1100px){.report-filter-grid{grid-template-columns:repeat(3,minmax(150px,1fr))}}@media(max-width:700px){.report-filter-grid,.report-summary{grid-template-columns:1fr}.report-search{grid-column:auto}.detail-header{align-items:flex-start}.report-actions{flex-wrap:wrap;justify-content:flex-start}}`],
})
export class ReportsPage implements OnDestroy {
  private readonly api = inject(ReportsApiService);
  private readonly feedback = inject(FeedbackService);
  private gestionSubscription?: Subscription;
  private paymentSubscription?: Subscription;
  private filterSubscription?: Subscription;
  private searchTimer?: ReturnType<typeof setTimeout>;
  private gestionSort: { field: string; direction: 'asc' | 'desc' } = { field: 'fechaLlamada', direction: 'desc' };
  readonly tab = signal<'gestiones' | 'pagos'>('gestiones');
  readonly gestionRows = signal<ReportGestion[]>([]); readonly paymentRows = signal<ReportPayment[]>([]);
  readonly gestionPage = signal<ServerPagination>(EMPTY_PAGE); readonly paymentPage = signal<ServerPagination>(EMPTY_PAGE);
  readonly gestionSummary = signal<ReportSummary>(EMPTY_GESTION_SUMMARY); readonly paymentSummary = signal<ReportSummary>(EMPTY_PAYMENT_SUMMARY);
  readonly gestionOptions = signal<GestionReportOptions>({ asesores: [], cosechas: [], carteras: [], nivel1: [], nivel2: [] });
  readonly paymentOptions = signal<PaymentReportOptions>({ ejecutivos: [], acuerdos: ['PAR', 'PPC', 'PPM'] });
  readonly gestionLoading = signal(true); readonly paymentLoading = signal(false); readonly exporting = signal(false);
  readonly number = formatNumber; readonly money = formatMoney; readonly date = formatDate; readonly time = formatTime;
  gestionForm = { fechaDesde: currentMonthStartLocalIsoDate(), fechaHasta: todayLocalIsoDate(), dni: '', asesor: '', cosecha: '', cartera: '', nivel1: '', nivel2: '', telefono: '', search: '' };
  paymentForm = { fechaDesde: currentMonthStartLocalIsoDate(), fechaHasta: todayLocalIsoDate(), codigo: '', dni: '', ejecutivo: '', tipoAcuerdo: '', search: '' };
  readonly theme = themeQuartz.withParams({ fontFamily: 'Segoe UI, sans-serif', fontSize: 11, headerFontSize: 10, rowHeight: 28, headerHeight: 34, spacing: 3, cellHorizontalPadding: 9, borderColor: '#e2e8f0', backgroundColor: '#fff', foregroundColor: '#344054', headerBackgroundColor: '#f6f8fb', headerTextColor: '#667085', accentColor: '#064fae', rowHoverColor: '#eff6ff', oddRowBackgroundColor: '#fbfcfe', wrapperBorderRadius: 0, browserColorScheme: 'light' });
  readonly defaults: ColDef<ReportGestion> = { sortable: true, resizable: true, minWidth: 78 };
  readonly gestionColumns: ColDef<ReportGestion>[] = [
    { field: 'idGestion', headerName: 'ID Gestión', width: 94 }, { field: 'canalGestion', headerName: 'Canal Gestión', width: 118 }, { field: 'canalAsignacion', headerName: 'Canal Asignación', width: 132 }, { field: 'dni', headerName: 'DNI', width: 100 }, { field: 'nombreCliente', headerName: 'Nombre Cliente', width: 230, tooltipField: 'nombreCliente' }, { field: 'cartera', headerName: 'Cartera', width: 128 }, { field: 'asesor', headerName: 'Asesor', width: 185 }, { field: 'equipo', headerName: 'Equipo', width: 165 }, { field: 'telefono', headerName: 'Teléfono', width: 115 }, { field: 'fechaLlamada', headerName: 'Fecha Llamada', width: 115, sort: 'desc', valueFormatter: p => formatDate(p.value as string) }, { field: 'cosecha', headerName: 'Cosecha', width: 170 }, { field: 'hora', headerName: 'Hora', width: 92, valueFormatter: p => formatTime(p.value as string | null) }, { field: 'nivel1', headerName: 'Nivel 1', width: 182 }, { field: 'nivel2', headerName: 'Nivel 2', width: 88 }, { field: 'fechaCompromiso', headerName: 'Fecha Compromiso', width: 136, valueFormatter: p => formatDate(p.value as string | null) }, { field: 'monto', headerName: 'Monto', width: 112, type: 'rightAligned', valueFormatter: p => formatMoney(Number(p.value), true) }, { field: 'observacion', headerName: 'Observación', width: 300, tooltipField: 'observacion' }, { field: 'medioGestion', headerName: 'Medio Gestión', width: 116 }, { field: 'puntaje', headerName: 'Puntaje', width: 86, type: 'rightAligned' },
  ];

  constructor() { this.loadGestionFilters(); this.loadGestiones(); }
  selectTab(tab: 'gestiones' | 'pagos'): void { if (this.tab() === tab) return; this.tab.set(tab); if (tab === 'pagos' && this.paymentRows().length === 0) { this.loadPaymentFilters(); this.loadPayments(); } }
  applyGestiones(): void { this.gestionPage.update(p => ({ ...p, page: 1 })); this.loadGestionFilters(); this.loadGestiones(); }
  applyPayments(): void { this.paymentPage.update(p => ({ ...p, page: 1 })); this.loadPaymentFilters(); this.loadPayments(); }
  clearGestion(): void { this.gestionForm = { fechaDesde: currentMonthStartLocalIsoDate(), fechaHasta: todayLocalIsoDate(), dni: '', asesor: '', cosecha: '', cartera: '', nivel1: '', nivel2: '', telefono: '', search: '' }; this.applyGestiones(); }
  clearPayments(): void { this.paymentForm = { fechaDesde: currentMonthStartLocalIsoDate(), fechaHasta: todayLocalIsoDate(), codigo: '', dni: '', ejecutivo: '', tipoAcuerdo: '', search: '' }; this.applyPayments(); }
  setGestionPage(page: number): void { if (page < 1 || page === this.gestionPage().page || page > this.gestionPage().lastPage) return; this.gestionPage.update(p => ({ ...p, page })); this.loadGestiones(); }
  setPaymentPage(page: number): void { if (page < 1 || page === this.paymentPage().page || page > this.paymentPage().lastPage) return; this.paymentPage.update(p => ({ ...p, page })); this.loadPayments(); }
  setGestionSize(event: Event): void { this.gestionPage.update(p => ({ ...p, page: 1, perPage: Number((event.target as HTMLSelectElement).value) })); this.loadGestiones(); }
  setPaymentSize(event: Event): void { this.paymentPage.update(p => ({ ...p, page: 1, perPage: Number((event.target as HTMLSelectElement).value) })); this.loadPayments(); }
  onGestionSort(event: SortChangedEvent<ReportGestion>): void { const column = event.api.getColumns()?.find(item => item.getSort()); const next = { field: column?.getColId() ?? 'fechaLlamada', direction: column?.getSort() === 'asc' ? 'asc' as const : 'desc' as const }; if (next.field === this.gestionSort.field && next.direction === this.gestionSort.direction) return; this.gestionSort = next; this.gestionPage.update(p => ({ ...p, page: 1 })); this.loadGestiones(); }
  queueGestionSearch(): void { this.queue(() => { this.gestionPage.update(p => ({ ...p, page: 1 })); this.loadGestiones(); }); }
  queuePaymentSearch(): void { this.queue(() => { this.paymentPage.update(p => ({ ...p, page: 1 })); this.loadPayments(); }); }
  exportGestiones(): void { this.exporting.set(true); this.api.exportGestiones(this.gestionFilters(), { ...this.gestionPage(), sortField: this.gestionSort.field, sortDirection: this.gestionSort.direction }).pipe(finalize(() => this.exporting.set(false))).subscribe({ next: file => this.download(file, 'reporte-gestiones.csv'), error: () => this.feedback.show('No se pudo exportar el reporte de gestiones.') }); }
  exportPayments(): void { this.exporting.set(true); this.api.exportPayments(this.paymentFilters(), { ...this.paymentPage(), sortField: 'fechaPago', sortDirection: 'desc' }).pipe(finalize(() => this.exporting.set(false))).subscribe({ next: file => this.download(file, 'reporte-pagos.csv'), error: () => this.feedback.show('No se pudo exportar el reporte de pagos.') }); }
  pageLabel(page: ServerPagination): string { if (page.total === 0) return 'Sin registros'; const from = (page.page - 1) * page.perPage + 1; return `Mostrando ${from}–${Math.min(page.page * page.perPage, page.total)} de ${this.number(page.total)}`; }
  ngOnDestroy(): void { this.gestionSubscription?.unsubscribe(); this.paymentSubscription?.unsubscribe(); this.filterSubscription?.unsubscribe(); clearTimeout(this.searchTimer); }
  private loadGestiones(): void { this.gestionSubscription?.unsubscribe(); this.gestionLoading.set(true); const page = this.gestionPage(); this.gestionSubscription = this.api.gestiones(this.gestionFilters(), { ...page, sortField: this.gestionSort.field, sortDirection: this.gestionSort.direction }).pipe(finalize(() => this.gestionLoading.set(false))).subscribe({ next: response => { this.gestionRows.set(response.data); this.gestionSummary.set(response.summary); this.gestionPage.set(response.pagination); }, error: () => this.feedback.show('No se pudo cargar el reporte de gestiones.') }); }
  private loadPayments(): void { this.paymentSubscription?.unsubscribe(); this.paymentLoading.set(true); const page = this.paymentPage(); this.paymentSubscription = this.api.pagos(this.paymentFilters(), { ...page, sortField: 'fechaPago', sortDirection: 'desc' }).pipe(finalize(() => this.paymentLoading.set(false))).subscribe({ next: response => { this.paymentRows.set(response.data); this.paymentSummary.set(response.summary); this.paymentPage.set(response.pagination); }, error: () => this.feedback.show('No se pudo cargar el reporte de pagos.') }); }
  private loadGestionFilters(): void { this.filterSubscription?.unsubscribe(); this.filterSubscription = this.api.gestionFilters(this.gestionForm).subscribe({ next: options => this.gestionOptions.set(options), error: () => this.feedback.show('No se pudieron cargar los filtros de gestiones.') }); }
  private loadPaymentFilters(): void { this.filterSubscription?.unsubscribe(); this.filterSubscription = this.api.paymentFilters(this.paymentForm).subscribe({ next: options => this.paymentOptions.set(options), error: () => this.feedback.show('No se pudieron cargar los filtros de pagos.') }); }
  private gestionFilters(): GestionReportFilters { return { ...this.gestionForm, asesor: this.gestionForm.asesor ? [this.gestionForm.asesor] : [], cosecha: this.gestionForm.cosecha ? [this.gestionForm.cosecha] : [], cartera: this.gestionForm.cartera ? [this.gestionForm.cartera] : [], nivel1: this.gestionForm.nivel1 ? [this.gestionForm.nivel1] : [], nivel2: this.gestionForm.nivel2 ? [this.gestionForm.nivel2] : [] }; }
  private paymentFilters(): PaymentReportFilters { return { ...this.paymentForm, tipoAcuerdo: this.paymentForm.tipoAcuerdo ? [this.paymentForm.tipoAcuerdo] : [] }; }
  private queue(action: () => void): void { clearTimeout(this.searchTimer); this.searchTimer = setTimeout(action, 400); }
  private download(file: Blob, filename: string): void { const url = URL.createObjectURL(file); const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; anchor.click(); URL.revokeObjectURL(url); }
}
