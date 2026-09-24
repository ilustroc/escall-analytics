import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { Icon } from '../../shared/components/icon/icon';

@Component({ selector: 'app-login', imports: [FormsModule, Icon], templateUrl: './login.html', changeDetection: ChangeDetectionStrategy.OnPush })
export class Login {
  readonly auth = inject(AuthService); private readonly router = inject(Router);
  email = ''; password = ''; readonly reveal = signal(false); readonly error = signal(''); readonly loading = signal(false);
  submit(): void {
    if (this.loading()) return;
    this.loading.set(true); this.error.set('');
    this.auth.login(this.email, this.password).subscribe({
      next: user => { this.loading.set(false); void this.router.navigateByUrl(user.mustChangePassword ? '/cambiar-contrasena' : '/dashboard/expertis'); },
      error: () => { this.loading.set(false); this.error.set('Las credenciales ingresadas no son válidas.'); },
    });
  }
}
