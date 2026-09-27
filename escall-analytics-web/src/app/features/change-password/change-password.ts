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
      </header>
      <div class="auth-action-copy">
        <span class="auth-eyebrow">ACTUALIZACIÓN DE CONTRASEÑA</span>
        <h1>Establece tu contraseña</h1>
        <p>Define una contraseña personal para continuar en ESCALL Analytics.</p>
      </div>
      <form class="auth-password-form" (ngSubmit)="submit()">
        <div class="auth-field-group">
          <label for="new-password">Nueva contraseña</label>
          <div class="auth-password-field">
            <app-icon name="LockKeyhole" [size]="18" />
            <input
              id="new-password"
              name="password"
              [type]="showPassword() ? 'text' : 'password'"
              autocomplete="new-password"
              placeholder="Crea una contraseña segura"
              aria-describedby="password-policy"
              [(ngModel)]="password"
            />
            <button type="button" class="icon-button" (click)="showPassword.set(!showPassword())" [attr.aria-label]="showPassword() ? 'Ocultar contraseña' : 'Mostrar contraseña'">
              <app-icon [name]="showPassword() ? 'EyeOff' : 'Eye'" [size]="18" />
            </button>
          </div>
        </div>
        <div class="auth-field-group">
          <label for="confirm-password">Confirmar contraseña</label>
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
        </div>
        <small id="password-policy" class="password-policy"><app-icon name="Info" [size]="15" />Usa al menos 12 caracteres, con mayúscula, minúscula, número y símbolo.</small>
        @if (error()) {
          <p class="form-error">{{ error() }}</p>
        }
        <button class="btn btn-primary" [disabled]="loading()">
          {{ loading() ? 'Guardando...' : 'Guardar y continuar' }}
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
