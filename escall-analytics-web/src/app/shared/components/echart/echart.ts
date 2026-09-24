import { afterNextRender, ChangeDetectionStrategy, Component, effect, ElementRef, inject, input, OnDestroy, viewChild } from '@angular/core';
import { init, use, EChartsType } from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { AriaComponent, GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { SVGRenderer } from 'echarts/renderers';
import type { EChartsOption } from 'echarts';
use([BarChart, LineChart, PieChart, AriaComponent, GridComponent, LegendComponent, TooltipComponent, SVGRenderer]);
@Component({ selector: 'app-echart', changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<div #canvas class="echart-canvas" role="img" [attr.aria-label]="label()" [style.height.px]="height()"></div>',
  styles: ':host { display: block; width: 100%; min-width: 0; } .echart-canvas { width: 100%; }' })
export class Echart implements OnDestroy {
  readonly options = input.required<EChartsOption>(); readonly height = input(210); readonly label = input.required<string>();
  private readonly canvas = viewChild.required<ElementRef<HTMLDivElement>>('canvas');
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private chart?: EChartsType; private observer?: ResizeObserver;
  constructor() {
    afterNextRender(() => {
      this.chart = init(this.canvas().nativeElement, undefined, { renderer: 'svg' });
      this.render();
      this.observer = new ResizeObserver(() => this.chart?.resize());
      this.observer.observe(this.host.nativeElement);
    });
    effect(() => { this.options(); this.render(); });
  }
  private render(): void { this.chart?.setOption({ animation: false, aria: { enabled: true }, ...this.options() }, { notMerge: true }); }
  ngOnDestroy(): void { this.observer?.disconnect(); this.chart?.dispose(); }
}
