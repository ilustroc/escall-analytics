import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { DashboardOperativoService } from '../services/dashboard-operativo.service';
import { HourlyMetric } from '../../../core/models/analytics.models';
import { ChartCard } from '../../../shared/components/chart-card/chart-card';
import { Echart } from '../../../shared/components/echart/echart';
import { horizontalChart, hourlyChart } from '../../../core/utils/chart-options';
import { shortAdvisor } from '../../../core/utils/format';
@Component({ selector: 'app-operativo-charts', imports: [FormsModule, ChartCard, Echart], changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div class="operativo-chart-grid"><app-chart-card title="Gestiones por hora" subtitle="Distribución de actividad · 08:00 a 18:00"><select chart-actions class="chart-select" aria-label="Métrica por hora" [ngModel]="metric()" (ngModelChange)="metric.set($event)"><option value="gestiones">Gestiones</option><option value="contactos">Contactos</option><option value="efectivos">Contacto efectivo</option><option value="ppc">PPC</option></select><app-echart [options]="hourly()" [height]="208" label="Actividad de gestión por hora" /></app-chart-card><app-chart-card title="Contacto efectivo por asesor" subtitle="Clientes con CE / clientes gestionados"><span chart-actions class="chart-unit">%</span><app-echart [options]="effective()" [height]="208" label="Porcentaje de contacto efectivo por asesor" /></app-chart-card><app-chart-card title="PPC por asesor" subtitle="Clientes con promesa confirmada"><app-echart [options]="ppc()" [height]="208" label="Cantidad de clientes con PPC por asesor" /></app-chart-card></div>` })
export class OperativoCharts {
  readonly data = inject(DashboardOperativoService); readonly metric = signal<HourlyMetric>('gestiones');
  readonly hourly = computed(() => hourlyChart(this.data.hourly(), this.metric()));
  readonly effective = computed(() => horizontalChart(this.data.effective().map(row => ({ name: shortAdvisor(row.asesor), value: row.porcentaje })), { percent: true, labelWidth: 118 }));
  readonly ppc = computed(() => horizontalChart(this.data.ppcByAdvisor().map(row => ({ name: shortAdvisor(row.asesor), value: row.ppc })), { labelWidth: 118 }));
}
