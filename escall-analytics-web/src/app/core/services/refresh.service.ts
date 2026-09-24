import { Injectable, signal } from '@angular/core';
@Injectable({ providedIn: 'root' })
export class RefreshService {
  readonly loading = signal(false);
  readonly updatedAt = signal('14/09/2026 18:30');
  readonly revision = signal(0);
  refresh(): void {
    this.loading.set(true);
    this.revision.update(value => value + 1);
  }
  complete(): void {
    this.updatedAt.set(new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date()).replace(',', ''));
    this.loading.set(false);
  }
}
