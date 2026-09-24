import { ChangeDetectionStrategy, Component, input } from '@angular/core';
@Component({ selector: 'app-chart-card', changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<section class="chart-card"><header class="chart-header"><div><h2>{{ title() }}</h2>@if (subtitle()) { <p>{{ subtitle() }}</p> }</div><div class="chart-actions"><ng-content select="[chart-actions]" /></div></header><ng-content /></section>` })
export class ChartCard { readonly title = input.required<string>(); readonly subtitle = input(''); }
