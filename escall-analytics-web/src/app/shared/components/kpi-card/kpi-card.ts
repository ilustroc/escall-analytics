import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { Icon, IconName } from '../icon/icon';
@Component({ selector: 'app-kpi-card', imports: [Icon], changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<article class="kpi-card" [class.kpi-accent]="variant() === 'accent'" [title]="hint() || subtitle()">
    <div class="flex items-center justify-between gap-2"><span class="kpi-label">{{ title() }}</span><span class="kpi-icon"><app-icon [name]="icon()" [size]="16" /></span></div>
    <div class="kpi-value">{{ value() }}</div>
    <div class="kpi-subtitle">@if (trend()) { <span class="text-success">{{ trend() }}</span> } {{ subtitle() }}</div>
  </article>` })
export class KpiCard {
  readonly title = input.required<string>(); readonly value = input.required<string>(); readonly subtitle = input('');
  readonly icon = input<IconName>('Activity'); readonly trend = input(''); readonly hint = input(''); readonly variant = input<'default' | 'accent'>('default');
}
