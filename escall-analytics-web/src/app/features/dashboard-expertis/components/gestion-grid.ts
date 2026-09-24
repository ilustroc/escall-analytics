import { ChangeDetectionStrategy, Component, computed, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AgGridAngular } from 'ag-grid-angular';
import {
  AllCommunityModule,
  ColDef,
  GetRowIdParams,
  ModuleRegistry,
  SortChangedEvent,
  themeQuartz,
} from 'ag-grid-community';
import { ServerPagination } from '../../../core/models/analytics-api.models';
import { GestionExpertis } from '../../../core/models/analytics.models';
import { formatDate, formatMoney, formatNumber, formatTime } from '../../../core/utils/format';
import { Icon } from '../../../shared/components/icon/icon';

ModuleRegistry.registerModules([AllCommunityModule]);

@Component({
  selector: 'app-gestion-grid',
  imports: [AgGridAngular, FormsModule, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<section class="detail-panel">
    <header class="detail-header">
      <div class="flex items-center gap-2">
        <h2>Detalle de gestiones</h2>
      </div>
      <div class="detail-tools">
        <button
          type="button"
          class="btn btn-ghost pin-button"
          [attr.aria-pressed]="pinned()"
          (click)="pinned.set(!pinned())"
          title="Fijar o liberar las columnas Fecha, DNI y Cliente"
        >
          <app-icon name="PanelLeftClose" [size]="14" />{{
            pinned() ? 'Liberar columnas' : 'Fijar columnas'
          }}</button
        ><label class="table-search"
          ><app-icon name="Search" [size]="14" /><input
            type="search"
            aria-label="Buscar en detalle de gestiones"
            placeholder="Buscar en la tabla..."
            [ngModel]="search()"
            (ngModelChange)="queueSearch($event)"
        /></label>
      </div>
    </header>
    <ag-grid-angular
      class="gestion-grid"
      [theme]="theme"
      [rowData]="rows()"
      [columnDefs]="columns()"
      [defaultColDef]="defaults"
      [rowHeight]="28"
      [headerHeight]="32"
      [tooltipShowDelay]="250"
      [enableBrowserTooltips]="true"
      [animateRows]="false"
      [localeText]="locale"
      [getRowId]="getRowId"
      [suppressCellFocus]="false"
      [loading]="loading()"
      (sortChanged)="onSortChanged($event)"
    />
    <footer class="grid-pager" aria-label="Paginación de detalle">
      <span>Página {{ pagination().page }} de {{ pagination().lastPage }}</span
      ><label
        >Filas
        <select [value]="pagination().perPage" (change)="changePageSize($event)">
          @for (size of pageSizes; track size) {
            <option [value]="size">{{ size }}</option>
          }
        </select></label
      >
      <div>
        <button
          type="button"
          class="btn btn-ghost"
          (click)="pageChange.emit(pagination().page - 1)"
          [disabled]="pagination().page <= 1"
        >
          Anterior</button
        ><button
          type="button"
          class="btn btn-ghost"
          (click)="pageChange.emit(pagination().page + 1)"
          [disabled]="pagination().page >= pagination().lastPage"
        >
          Siguiente
        </button>
      </div>
    </footer>
  </section>`,
})
export class GestionGrid {
  private searchTimer?: ReturnType<typeof setTimeout>;
  private lastEmittedSort = 'fechaLlamada:desc';
  readonly rows = input.required<GestionExpertis[]>();
  readonly pagination = input.required<ServerPagination>();
  readonly loading = input(false);
  readonly pageChange = output<number>();
  readonly pageSizeChange = output<number>();
  readonly searchChange = output<string>();
  readonly sortChange = output<{ field: string; direction: 'asc' | 'desc' }>();
  readonly search = signal('');
  readonly pinned = signal(true);
  readonly number = formatNumber;
  readonly pageSizes = [25, 50, 100];
  readonly text = (value: string | null | undefined): string => value || '—';
  readonly theme = themeQuartz.withParams({
    fontFamily: 'Segoe UI, sans-serif',
    fontSize: 11,
    headerFontSize: 10,
    rowHeight: 28,
    headerHeight: 32,
    spacing: 3,
    cellHorizontalPadding: 9,
    borderColor: '#E2E8F0',
    backgroundColor: '#FFFFFF',
    foregroundColor: '#344054',
    headerBackgroundColor: '#F6F8FB',
    headerTextColor: '#667085',
    accentColor: '#064FAE',
    rowHoverColor: '#EFF6FF',
    oddRowBackgroundColor: '#FBFCFE',
    wrapperBorderRadius: 0,
    browserColorScheme: 'light',
  });
  readonly defaults: ColDef<GestionExpertis> = {
    sortable: true,
    filter: false,
    resizable: true,
    minWidth: 75,
    suppressMovable: false,
  };
  readonly effectiveClassRules: NonNullable<ColDef<GestionExpertis>['cellClassRules']> = {
    'grid-effective': (params) => params.value === 'CONTACTO EFECTIVO',
  };
  readonly ppcClassRules: NonNullable<ColDef<GestionExpertis>['cellClassRules']> = {
    'grid-ppc': (params) => params.value === 'PPC',
  };
  readonly getRowId = (params: GetRowIdParams<GestionExpertis>): string =>
    String(params.data.idGestion);
  readonly columns = computed<ColDef<GestionExpertis>[]>(() => [
    {
      field: 'fechaLlamada',
      headerName: 'Fecha',
      width: 98,
      sort: 'desc',
      pinned: this.pinned() ? 'left' : null,
      valueFormatter: (params) => formatDate(params.value as string),
    },
    {
      field: 'hora',
      headerName: 'Hora',
      width: 82,
      valueFormatter: (params) => formatTime(params.value as string | null),
    },
    { field: 'dni', headerName: 'DNI', width: 92, pinned: this.pinned() ? 'left' : null },
    {
      field: 'nombreCliente',
      headerName: 'Nombre Cliente',
      width: 224,
      pinned: this.pinned() ? 'left' : null,
      tooltipField: 'nombreCliente',
      valueFormatter: (params) => this.text(params.value as string | null),
    },
    { field: 'asesor', headerName: 'Asesor', width: 178 },
    {
      field: 'cosecha',
      headerName: 'Cosecha',
      width: 169,
      valueFormatter: (params) => this.text(params.value as string | null),
    },
    {
      field: 'nivel1',
      headerName: 'Nivel 1',
      width: 183,
      cellClassRules: this.effectiveClassRules,
    },
    { field: 'nivel2', headerName: 'Nivel 2', width: 83, cellClassRules: this.ppcClassRules },
    {
      field: 'fechaCompromiso',
      headerName: 'Fecha Compromiso',
      width: 136,
      valueFormatter: (params) => formatDate(params.value as string | null),
    },
    {
      field: 'monto',
      headerName: 'Monto',
      width: 105,
      type: 'rightAligned',
      valueFormatter: (params) => formatMoney(Number(params.value), true),
    },
    { field: 'medioGestion', headerName: 'Medio Gestión', width: 122 },
    {
      field: 'observacion',
      headerName: 'Observación',
      width: 280,
      tooltipField: 'observacion',
      valueFormatter: (params) => this.text(params.value as string | null),
    },
    {
      field: 'telefono',
      headerName: 'Teléfono',
      width: 105,
      valueFormatter: (params) => this.text(params.value as string | null),
    },
    { field: 'equipo', headerName: 'Equipo', width: 152 },
  ]);
  readonly locale: Record<string, string> = {
    noRowsToShow: 'No hay gestiones que coincidan',
    loadingOoo: 'Cargando...',
    searchOoo: 'Buscar...',
    filterOoo: 'Filtrar...',
  };

  queueSearch(search: string): void {
    this.search.set(search);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.searchChange.emit(search), 400);
  }

  changePageSize(event: Event): void {
    this.pageSizeChange.emit(Number((event.target as HTMLSelectElement).value));
  }

  onSortChanged(event: SortChangedEvent<GestionExpertis>): void {
    const column = event.api.getColumns()?.find((item) => item.getSort());
    const sort = column
      ? {
          field: column.getColId(),
          direction: column.getSort() === 'asc' ? ('asc' as const) : ('desc' as const),
        }
      : { field: 'fechaLlamada', direction: 'desc' as const };
    const key = `${sort.field}:${sort.direction}`;
    if (key === this.lastEmittedSort) return;
    this.lastEmittedSort = key;
    this.sortChange.emit(sort);
  }
}
