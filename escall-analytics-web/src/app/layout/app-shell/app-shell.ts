import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterOutlet } from '@angular/router';
import { filter, map } from 'rxjs';
import { Sidebar } from '../sidebar/sidebar';
import { Topbar } from '../topbar/topbar';
@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, Sidebar, Topbar],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(keydown.escape)': 'drawer.set(false)' },
  template: `<div
    class="app-shell"
    [class.sidebar-collapsed]="collapsed()"
    [class.drawer-open]="drawer()"
  >
    <a href="#main-content" class="skip-link">Saltar al contenido</a>
    @if (drawer()) {
      <button
        type="button"
        class="drawer-backdrop"
        aria-label="Cerrar menú"
        (click)="drawer.set(false)"
      ></button>
    }
    <app-sidebar
      [collapsed]="collapsed()"
      (toggle)="toggleSidebar()"
      (navigate)="drawer.set(false)"
    />
    <div class="workspace">
      <app-topbar [title]="title()" (menu)="openDrawer()" />
      <main id="main-content" tabindex="-1"><router-outlet /></main>
      <footer class="app-footer">
        <span>© 2026 ESCALL Perú <span class="footer-divider">|</span> Analytics</span>
      </footer>
    </div>
  </div>`,
})
export class AppShell {
  private readonly router = inject(Router);
  readonly collapsed = signal(false);
  readonly drawer = signal(false);
  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );
  readonly title = computed(() =>
    this.url().includes('operativo')
      ? 'Operación diaria'
      : this.url().includes('importaciones')
        ? 'Importaciones Expertis'
        : this.url().includes('administracion')
          ? 'Usuarios'
          : 'Dashboard Expertis',
  );
  openDrawer(): void {
    this.collapsed.set(false);
    this.drawer.set(true);
  }
  toggleSidebar(): void {
    if (window.matchMedia('(max-width: 767px)').matches) this.drawer.set(false);
    else this.collapsed.update((value) => !value);
  }
}
