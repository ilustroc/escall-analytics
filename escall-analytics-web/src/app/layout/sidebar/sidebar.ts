import { ChangeDetectionStrategy, Component, inject, input, output } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { Icon } from '../../shared/components/icon/icon';
@Component({ selector: 'app-sidebar', imports: [RouterLink, RouterLinkActive, Icon], templateUrl: './sidebar.html', changeDetection: ChangeDetectionStrategy.OnPush })
export class Sidebar {
  readonly auth = inject(AuthService); private readonly router = inject(Router);
  readonly collapsed = input(false); readonly toggle = output<void>(); readonly navigate = output<void>();
  logout(): void { this.auth.logout().subscribe(() => void this.router.navigateByUrl('/login')); }
}
