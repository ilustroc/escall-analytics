import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { Icon } from '../icon/icon';
@Component({
  selector: 'app-empty-state',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div class="empty-state" role="status">
    <span class="empty-icon"><app-icon name="Search" [size]="24" /></span>
    <h2>{{ title() }}</h2>
    <p>{{ message() }}</p>
    <button class="btn btn-secondary" (click)="reset.emit()">Restablecer filtros</button>
  </div>`,
})
export class EmptyState {
  readonly title = input('Sin resultados para estos filtros');
  readonly message = input(
    'Prueba otro periodo, asesor o cosecha. Hay datos del 1 al 14 de septiembre de 2026.',
  );
  readonly reset = output<void>();
}
