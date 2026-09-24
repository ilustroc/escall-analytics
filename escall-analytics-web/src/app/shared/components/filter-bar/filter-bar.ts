import { ChangeDetectionStrategy, Component } from '@angular/core';
@Component({ selector: 'app-filter-bar', changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<section class="filter-bar" aria-label="Filtros del dashboard"><ng-content /></section>' })
export class FilterBar {}
