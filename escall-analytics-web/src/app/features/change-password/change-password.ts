import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { Icon } from '../../shared/components/icon/icon';

@Component({
  selector: 'app-change-password',
  imports: [FormsModule, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div class="auth-action-page">
    <section class="auth-action-card">
      <header class="auth-action-brand">
        <img src="/assets/branding/logo.png" alt="ESCALL" />
        <span class="auth-security-chip"><app-icon name="ShieldCheck" [size]="14" />Acceso seguro</span>
      </header>
      <div class="auth-action-copy">
        <span class="auth-eyebrow">SEGURIDAD DE CUENTA</span>
        <h1>Cambia tu contraseña</h1>
        <p>Por seguridad, establece una contraseña personal antes de ingresar.</p>
      </div>
      <form (ngSubmit)="submit()">
        <label for="new-password">Nueva contraseña</label>
        <div class="auth-password-field">
          <app-icon name="LockKeyhole" [size]="18" />
          <input
            id="new-password"
            name="password"
            [type]="showPassword() ? 'text' : 'password'"
            autocomplete="new-password"
            placeholder="Crea una contraseña segura"
            [(ngModel)]="password"
          />
          <button type="button" class="icon-button" (click)="showPassword.set(!showPassword())" [attr.aria-label]="showPassword() ? 'Ocultar contraseña' : 'Mostrar contraseña'">
            <app-icon [name]="showPassword() ? 'EyeOff' : 'Eye'" [size]="18" />
          </button>
        </div>
        <label for="confirm-password">Confirmar nueva contraseña</label>
        <div class="auth-password-field">
          <app-icon name="LockKeyhole" [size]="18" />
          <input
            id="confirm-password"
            name="confirmation"
            [type]="showConfirmation() ? 'text' : 'password'"
            autocomplete="new-password"
            placeholder="Repite la contraseña"
            [(ngModel)]="confirmation"
          />
          <button type="button" class="icon-button" (click)="showConfirmation.set(!showConfirmation())" [attr.aria-label]="showConfirmation() ? 'Ocultar contraseña' : 'Mostrar contraseña'">
            <app-icon [name]="showConfirmation() ? 'EyeOff' : 'Eye'" [size]="18" />
          </button>
        </div>
        <small class="password-policy"><app-icon name="Info" [size]="14" />Mínimo 12 caracteres, mayúscula, minúscula, número y símbolo.</small>
        @if (error()) {
          <p class="form-error">{{ error() }}</p>
        }
        <button class="btn btn-primary" [disabled]="loading()">
          {{ loading() ? 'Guardando...' : 'Guardar contraseña' }}
          <app-icon name="ArrowRight" [size]="15" />
        </button>
      </form>
    </section>
  </div>`,
})
export class ChangePassword {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  password = '';
  confirmation = '';
  readonly loading = signal(false);
  readonly error = signal('');
  readonly showPassword = signal(false);
  readonly showConfirmation = signal(false);
  submit(): void {
    this.loading.set(true);
    this.error.set('');
    this.auth.changePassword(this.password, this.confirmation).subscribe({
      next: () => {
        this.loading.set(false);
        void this.router.navigateByUrl('/dashboard/expertis');
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Verifica que la contraseña cumpla la política solicitada.');
      },
    });
  }
}
