import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AdminUser, AdminUsersApiService } from '../../core/services/admin-users-api.service';
import { FeedbackService } from '../../core/services/feedback.service';
import { Icon } from '../../shared/components/icon/icon';

@Component({
  selector: 'app-admin-users',
  imports: [FormsModule, Icon, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<section class="dashboard-page admin-page">
    <div class="page-header">
      <div>
        <h1>Administración de usuarios</h1>
        <p>Accesos y roles de ESCALL Analytics.</p>
      </div>
      <button class="btn btn-primary" (click)="showForm.set(true)">
        <app-icon name="Users" [size]="15" />Nuevo usuario
      </button>
    </div>
    @if (showForm()) {
      <section class="compact-form">
        <strong>Nuevo usuario</strong><input [(ngModel)]="name" placeholder="Nombre" /><input
          [(ngModel)]="email"
          type="email"
          placeholder="Correo"
        /><select [(ngModel)]="role">
          <option value="supervisor">Supervisor</option>
          <option value="proveedor">Proveedor</option></select
        ><button class="btn btn-primary" (click)="create()">Crear</button
        ><button class="btn btn-secondary" (click)="showForm.set(false)">Cancelar</button>
      </section>
    }
    @if (temporaryPassword()) {
      <section class="temporary-password">
        <strong>Usuario creado</strong><span>Copie esta contraseña. Se mostrará una sola vez.</span
        ><code>{{ temporaryPassword() }}</code
        ><button (click)="temporaryPassword.set('')" class="btn btn-secondary">Cerrar</button>
      </section>
    }
    <section class="detail-panel">
      <div class="detail-header">
        <div>
          <h2>Usuarios</h2>
          <p>{{ users().length }} registros</p>
        </div>
      </div>
      <div class="users-table-wrap">
        <table class="compact-table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Correo</th>
              <th>Rol</th>
              <th>Estado</th>
              <th>Último acceso</th>
              <th>Creado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            @for (user of users(); track user.id) {
              <tr>
                <td>
                  <strong>{{ user.name }}</strong>
                </td>
                <td>{{ user.email }}</td>
                <td>{{ user.role }}</td>
                <td>
                  <span class="status-chip" [class.off]="!user.isActive">{{
                    user.isActive ? 'Activo' : 'Inactivo'
                  }}</span>
                </td>
                <td>
                  {{ user.lastLoginAt ? (user.lastLoginAt | date: 'dd/MM/yyyy HH:mm') : '-' }}
                </td>
                <td>{{ user.createdAt | date: 'dd/MM/yyyy' }}</td>
                <td>
                  <button class="table-link" (click)="toggle(user)">
                    {{ user.isActive ? 'Desactivar' : 'Activar' }}</button
                  ><button class="table-link" (click)="reset(user)">Resetear</button>
                </td>
              </tr>
            } @empty {
              <tr>
                <td colspan="7">No hay usuarios.</td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    </section>
  </section>`,
})
export class AdminUsers {
  private readonly api = inject(AdminUsersApiService);
  private readonly feedback = inject(FeedbackService);
  readonly users = signal<AdminUser[]>([]);
  readonly showForm = signal(false);
  readonly temporaryPassword = signal('');
  name = '';
  email = '';
  role: 'supervisor' | 'proveedor' = 'supervisor';
  constructor() {
    this.load();
  }
  load(): void {
    this.api.list().subscribe({
      next: (response) => this.users.set(response.data),
      error: () => this.feedback.show('No se pudo obtener los usuarios.'),
    });
  }
  create(): void {
    this.api.create({ name: this.name, email: this.email, role: this.role }).subscribe({
      next: (response) => {
        this.temporaryPassword.set(response.temporaryPassword);
        this.showForm.set(false);
        this.name = '';
        this.email = '';
        this.load();
      },
      error: () => this.feedback.show('No se pudo crear el usuario.'),
    });
  }
  toggle(user: AdminUser): void {
    this.api.setActive(user, !user.isActive).subscribe({
      next: () => this.load(),
      error: () => this.feedback.show('No se pudo actualizar el usuario.'),
    });
  }
  reset(user: AdminUser): void {
    this.api.resetPassword(user).subscribe({
      next: (response) => this.temporaryPassword.set(response.temporaryPassword),
      error: () => this.feedback.show('No se pudo resetear la contraseña.'),
    });
  }
}
