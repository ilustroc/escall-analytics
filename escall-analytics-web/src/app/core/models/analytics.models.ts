export type Nivel1 = 'CONTACTO EFECTIVO' | 'CONTACTO NO EFECTIVO' | 'NO CONTACTO';
export type Nivel2 = 'PPC' | 'PPM' | 'PAR' | 'VLL' | 'RPP' | 'CAN' | 'TAT' | 'REN' | 'REC' | 'FAL' | 'MCT' | 'DES' | 'GRB' | 'HOM' | 'ILC' | 'NOC' | 'NTT';
export interface GestionExpertis {
  idGestion: number;
  canalGestion: string;
  canalAsignacion: string;
  dni: string;
  nombreCliente: string | null;
  cartera: string | null;
  asesor: string;
  equipo: string;
  telefono: string | null;
  fechaLlamada: string;
  cosecha: string | null;
  hora: string | null;
  nivel1: string;
  nivel2: string;
  fechaCompromiso: string | null;
  monto: number;
  observacion: string | null;
  medioGestion: string;
}
export interface PagoExpertis { id: number; dni: string; fechaPago: string; montoPago: number; }
export interface Tipificacion { codigo: Nivel2; gestion: 'CEF' | 'CNE' | 'NOC'; peso: number; descripcion: string; }
export interface AnalyticsFilters {
  desde: string;
  hasta: string;
  asesores: string[];
  cosechas: string[];
  nivel1: string[];
  nivel2: string[];
  equipos: string[];
}
export interface DashboardMetrics {
  gestiones: number;
  clientes: number;
  contactados: number;
  efectivos: number;
  contactabilidad: number;
  contactoEfectivo: number;
  intensidad: number;
  compromisos: number;
  montoComprometido: number;
  clientesPago: number;
  montoPagado: number;
  cumplimiento: number;
  ppc: number;
}
export interface AdvisorRanking {
  asesor: string;
  posicion: number;
  gestiones: number;
  clientes: number;
  contactados: number;
  efectivos: number;
  contactoEfectivo: number;
  ppc: number;
  montoComprometido: number;
  clientesPago: number;
  montoPagado: number;
  intensidad: number;
}
export interface DailyEvolution { fecha: string; gestiones: number; clientes: number; }
export interface HourlyActivity { hora: string; gestiones: number; contactos: number; efectivos: number; ppc: number; }
export type HourlyMetric = 'gestiones' | 'contactos' | 'efectivos' | 'ppc';
export interface Distribution { name: string; value: number; }
export interface AdvisorEffective { asesor: string; clientes: number; contactoEfectivo: number; porcentaje: number; }
export interface AdvisorPpc { asesor: string; ppc: number; }
export interface RecentActivity { hora: string | null; asesor: string; dni: string; nivel1: string; nivel2: string; monto: number; }
