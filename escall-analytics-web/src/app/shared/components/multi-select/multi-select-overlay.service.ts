import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class MultiSelectOverlayService {
  readonly activeId = signal<string | null>(null);

  activate(id: string): void { this.activeId.set(id); }

  clear(id: string): void {
    if (this.activeId() === id) this.activeId.set(null);
  }
}
