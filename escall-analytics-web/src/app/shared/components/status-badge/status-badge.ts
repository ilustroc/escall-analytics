import { ChangeDetectionStrategy, Component, input } from '@angular/core';
@Component({ selector: 'app-status-badge', changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<span class="status-badge" [class.status-positive]="positive()">{{ label() }}</span>' })
export class StatusBadge { readonly label = input.required<string>(); readonly positive = input(false); }
