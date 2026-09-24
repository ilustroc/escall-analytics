import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { DashboardOperativoService } from '../services/dashboard-operativo.service';
import { FilterBar } from '../../../shared/components/filter-bar/filter-bar';
import { Icon } from '../../../shared/components/icon/icon';
import { MultiSelect } from '../../../shared/components/multi-select/multi-select';
@Component({ selector: 'app-operativo-filters', imports: [FormsModule, FilterBar, Icon, MultiSelect], changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-filter-bar><label class="field date-field"><span>Fecha de gestión</span><input type="date" aria-label="Fecha de gestión" [ngModel]="data.filters().desde" (ngModelChange)="data.setDate($event)" /></label><app-multi-select label="Asesor" [options]="data.filterOptions().asesores" [selected]="data.filters().asesores" allLabel="Todos" (selectionChange)="data.patch({asesores: $event})" /><app-multi-select label="Equipo" [options]="data.filterOptions().equipos" [selected]="data.filters().equipos" allLabel="Todos" (selectionChange)="data.patch({equipos: $event})" /><app-multi-select label="Cosecha" [options]="data.filterOptions().cosechas" [selected]="data.filters().cosechas" allLabel="Todas" (selectionChange)="data.patch({cosechas: $event})" /><button type="button" class="btn btn-ghost filter-clear" (click)="data.reset()"><app-icon name="ListFilter" [size]="15" />Limpiar filtros</button><button type="button" class="btn btn-primary" (click)="data.refresh()" [disabled]="data.dashboardLoading()"><app-icon name="RefreshCw" [size]="14" />Actualizar</button></app-filter-bar>` })
export class OperativoFilters { readonly data = inject(DashboardOperativoService); }
