import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { DashboardExpertisService } from '../services/dashboard-expertis.service';
import { ChartCard } from '../../../shared/components/chart-card/chart-card';
import { Echart } from '../../../shared/components/echart/echart';
import { dailyChart, donutChart, paymentAgreementChart, paymentRankingChart } from '../../../core/utils/chart-options';
@Component({ selector: 'app-expertis-charts', imports: [ChartCard, Echart], changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div class="expertis-chart-grid"><app-chart-card class="evolution-panel" title="Evolución diaria" subtitle="Volumen de gestión en el periodo"><span chart-actions class="chart-unit">Gestiones / clientes</span><app-echart [options]="daily()" [height]="236" label="Evolución diaria de gestiones y clientes gestionados" /></app-chart-card>
  <app-chart-card class="level-panel" title="Nivel de contacto" subtitle="Mejor resultado por cliente"><div class="donut-wrap"><app-echart [options]="donut()" [height]="156" label="Distribución por nivel de contacto" /><div class="donut-center"><strong>{{ total() }}</strong><span>clientes</span></div></div><div class="donut-legend">@for (row of distribution(); track row.name; let i = $index) { <div><span class="legend-dot" [style.background]="colors[i]"></span><span>{{ labels[i] }}</span><strong>{{ row.value }}</strong><small>{{ percentage(row.value) }}</small></div> }</div></app-chart-card></div>
  <div class="secondary-chart-grid"><app-chart-card title="Pagos por tipo de acuerdo" subtitle="Monto pagado y cantidad de pagos"><app-echart [options]="agreement()" [height]="166" label="Pagos PPC, PPM y PAR" /></app-chart-card><app-chart-card title="Ranking de asesores" subtitle="Top 10 por monto pagado"><app-echart [options]="ranking()" [height]="166" label="Ranking de asesores por monto pagado" /></app-chart-card></div>` })
export class ExpertisCharts {
  readonly data = inject(DashboardExpertisService);
  readonly colors = ['#C3D1E2', '#064FAE', '#6C9BD5']; readonly labels = ['No contacto', 'Contacto efectivo', 'Contacto no efectivo'];
  readonly daily = computed(() => dailyChart(this.data.daily()));
  readonly agreement = computed(() => paymentAgreementChart(this.data.paymentsByAgreement()));
  readonly ranking = computed(() => paymentRankingChart(this.data.paymentRanking()));
  readonly distribution = computed(() => this.data.level1());
  readonly total = computed(() => this.distribution().reduce((sum, row) => sum + row.value, 0));
  readonly donut = computed(() => donutChart(this.distribution()));
  percentage(value: number): string { return (this.total() ? value / this.total() * 100 : 0).toFixed(1) + '%'; }
}
