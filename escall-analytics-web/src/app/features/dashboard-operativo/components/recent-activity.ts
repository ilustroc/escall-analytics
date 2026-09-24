import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  OnDestroy,
  signal,
} from '@angular/core';
import { RecentActivity as RecentActivityRow } from '../../../core/models/analytics.models';
import { RefreshService } from '../../../core/services/refresh.service';
import { formatMoney, formatTime, shortAdvisor } from '../../../core/utils/format';
import { Icon } from '../../../shared/components/icon/icon';
import { StatusBadge } from '../../../shared/components/status-badge/status-badge';
@Component({
  selector: 'app-recent-activity',
  imports: [Icon, StatusBadge],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<section class="recent-panel">
    <header class="detail-header">
      <div>
        <h2>Últimas gestiones</h2>
        <p>Actividad del periodo seleccionado</p>
      </div>
      <button
        type="button"
        class="btn btn-ghost"
        (click)="replay()"
        aria-label="Actualizar actividad"
        title="Mostrar nuevamente las últimas gestiones"
      >
        <app-icon name="RefreshCw" [size]="14" />
      </button>
    </header>
    <div class="recent-status">
      <span class="online-dot" [class.pulse]="replaying()"></span
      >{{ replaying() ? 'Actualizando actividad…' : 'Actividad sincronizada' }}
    </div>
    <div class="recent-list">
      @for (row of visible(); track row.dni + row.hora + row.asesor; let first = $first) {
        <div class="recent-row" [class.recent-highlight]="first && replaying()">
          <time>{{ time(row.hora).slice(0, 5) }}</time>
          <div class="recent-description">
            <strong>{{ name(row.asesor) }}</strong
            ><span>DNI {{ row.dni }}</span>
          </div>
          <div class="recent-result">
            <app-status-badge [label]="row.nivel2" [positive]="row.nivel2 === 'PPC'" />
            @if (row.monto) {
              <span>{{ money(row.monto) }}</span>
            }
          </div>
        </div>
      }
    </div>
    <div class="recent-footer">
      <app-icon name="Clock3" [size]="12" />Últimas {{ rows().length }} gestiones · hora local
    </div>
  </section>`,
})
export class RecentActivity implements OnDestroy {
  readonly rows = input.required<RecentActivityRow[]>();
  readonly name = shortAdvisor;
  readonly money = formatMoney;
  readonly time = formatTime;
  private readonly refresh = inject(RefreshService);
  private timer?: ReturnType<typeof setInterval>;
  readonly cursor = signal(0);
  readonly replaying = signal(false);
  readonly visible = computed(() => this.rows().slice(this.cursor()));
  constructor() {
    effect(() => {
      this.refresh.revision();
      this.rows();
      this.replay();
    });
  }
  replay(): void {
    clearInterval(this.timer);
    this.cursor.set(Math.min(3, Math.max(0, this.rows().length - 1)));
    this.replaying.set(this.cursor() > 0);
    if (this.cursor() === 0) return;
    this.timer = setInterval(() => {
      this.cursor.update((value) => Math.max(0, value - 1));
      if (this.cursor() === 0) {
        this.replaying.set(false);
        clearInterval(this.timer);
      }
    }, 1000);
  }
  ngOnDestroy(): void {
    clearInterval(this.timer);
  }
}
