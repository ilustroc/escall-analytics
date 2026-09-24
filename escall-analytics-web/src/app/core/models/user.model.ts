export type UserRole = 'administrador' | 'supervisor' | 'proveedor';

export interface AnalyticsUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  isActive: boolean;
  mustChangePassword: boolean;
  lastLoginAt: string | null;
}

/** Legacy mock shape retained only for comparison fixtures. */
export interface DemoUser { nombre: string; email: string; rol: 'ADMINISTRADOR' | 'SUPERVISOR' | 'PROVEEDOR'; iniciales: string; }

export const roleLabel = (role: UserRole): string => ({ administrador: 'ADMINISTRADOR', supervisor: 'SUPERVISOR', proveedor: 'PROVEEDOR' })[role];

export const initials = (name: string): string => name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
