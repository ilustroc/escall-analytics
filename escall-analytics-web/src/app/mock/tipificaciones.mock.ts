import { Nivel1, Tipificacion } from '../core/models/analytics.models';
export const NIVEL_1: readonly Nivel1[] = ['CONTACTO EFECTIVO', 'CONTACTO NO EFECTIVO', 'NO CONTACTO'];
export const GESTION_NIVEL: Record<Tipificacion['gestion'], Nivel1> = {
  CEF: 'CONTACTO EFECTIVO', CNE: 'CONTACTO NO EFECTIVO', NOC: 'NO CONTACTO',
};
export const TIPIFICACIONES: readonly Tipificacion[] = [
  { codigo: 'PPC', gestion: 'CEF', peso: 1, descripcion: 'Promesa de pago confirmada' },
  { codigo: 'PPM', gestion: 'CEF', peso: 2, descripcion: 'Promesa de pago por monto parcial' },
  { codigo: 'PAR', gestion: 'CEF', peso: 3, descripcion: 'Pago a regularizar' },
  { codigo: 'VLL', gestion: 'CEF', peso: 4, descripcion: 'Volver a llamar' },
  { codigo: 'RPP', gestion: 'CEF', peso: 5, descripcion: 'Recordatorio de promesa de pago' },
  { codigo: 'CAN', gestion: 'CEF', peso: 6, descripcion: 'Cliente indica cancelación' },
  { codigo: 'TAT', gestion: 'CEF', peso: 7, descripcion: 'Titular atendido' },
  { codigo: 'REN', gestion: 'CEF', peso: 8, descripcion: 'Renegociación' },
  { codigo: 'REC', gestion: 'CEF', peso: 9, descripcion: 'Reclamo del cliente' },
  { codigo: 'FAL', gestion: 'CNE', peso: 10, descripcion: 'Contacto con familiar' },
  { codigo: 'MCT', gestion: 'CNE', peso: 11, descripcion: 'Mensaje con tercero' },
  { codigo: 'DES', gestion: 'NOC', peso: 12, descripcion: 'Número desconocido' },
  { codigo: 'GRB', gestion: 'NOC', peso: 13, descripcion: 'Grabadora' },
  { codigo: 'HOM', gestion: 'NOC', peso: 14, descripcion: 'Homónimo' },
  { codigo: 'ILC', gestion: 'NOC', peso: 15, descripcion: 'Intento de llamada cortada' },
  { codigo: 'NOC', gestion: 'NOC', peso: 16, descripcion: 'No contesta' },
  { codigo: 'NTT', gestion: 'NOC', peso: 17, descripcion: 'Número no pertenece al titular' },
];
