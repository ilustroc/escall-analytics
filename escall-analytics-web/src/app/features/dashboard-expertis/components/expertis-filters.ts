import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { DashboardExpertisService } from '../services/dashboard-expertis.service';
import { FeedbackService } from '../../../core/services/feedback.service';
import { FilterBar } from '../../../shared/components/filter-bar/filter-bar';
import { MultiSelect } from '../../../shared/components/multi-select/multi-select';
import { Icon } from '../../../shared/components/icon/icon';
@Component({
  selector: 'app-expertis-filters',
  imports: [FormsModule, FilterBar, MultiSelect, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-filter-bar
      ><label class="field date-field"
        ><span>Fecha desde</span
        ><input
          aria-label="Fecha desde"
          type="date"
          [ngModel]="data.filters().desde"
          (ngModelChange)="data.patch({ desde: $event })" /></label
      ><label class="field date-field"
        ><span>Fecha hasta</span
        ><input
          aria-label="Fecha hasta"
          type="date"
          [ngModel]="data.filters().hasta"
          (ngModelChange)="data.patch({ hasta: $event })" /></label
      ><app-multi-select
        label="Asesor"
        [options]="data.filterOptions().asesores"
        [selected]="data.filters().asesores"
        allLabel="Todos"
        (selectionChange)="data.patch({ asesores: $event })"
      /><app-multi-select
        label="Cosecha"
        [options]="data.filterOptions().cosechas"
        [selected]="data.filters().cosechas"
        allLabel="Todas"
        (selectionChange)="data.patch({ cosechas: $event })"
      /><app-multi-select
        label="Nivel 1"
        [options]="data.filterOptions().nivel1"
        [selected]="data.filters().nivel1"
        allLabel="Todos"
        (selectionChange)="data.patch({ nivel1: $event })"
      /><app-multi-select
        label="Nivel 2"
        [options]="data.filterOptions().nivel2"
        [selected]="data.filters().nivel2"
        allLabel="Todos"
        (selectionChange)="data.patch({ nivel2: $event })"
      /><button
        type="button"
        class="btn btn-ghost filter-clear"
        (click)="data.reset()"
        title="Restablecer el periodo y limpiar filtros"
      >
        <app-icon name="ListFilter" [size]="15" />Limpiar filtros</button
      ><button
        type="button"
        class="btn btn-secondary filter-export"
        (click)="feedback.show('La exportación estará disponible próximamente.')"
      >
        <app-icon name="ArrowDownToLine" [size]="14" />Exportar
      </button></app-filter-bar
    >
    @if (data.invalidDates()) {
      <p class="form-error" role="alert">
        La fecha desde debe ser anterior o igual a la fecha hasta.
      </p>
    }`,
})
export class ExpertisFilters {
  readonly data = inject(DashboardExpertisService);
  readonly feedback = inject(FeedbackService);
}
