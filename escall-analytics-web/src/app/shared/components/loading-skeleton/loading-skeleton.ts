import { ChangeDetectionStrategy, Component } from '@angular/core';
@Component({ selector: 'app-loading-skeleton', changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div class="skeleton-layout" role="status" aria-label="Actualizando indicadores"><span class="sr-only">Actualizando indicadores</span><div class="grid grid-cols-2 lg:grid-cols-5 gap-2.5">@for (item of cards; track item) { <div class="skeleton h-28"></div> }</div><div class="grid grid-cols-1 lg:grid-cols-2 gap-2.5 mt-2.5"><div class="skeleton h-64"></div><div class="skeleton h-64"></div></div></div>` })
export class LoadingSkeleton { readonly cards = [1, 2, 3, 4, 5]; }
