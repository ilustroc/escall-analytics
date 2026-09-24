import { ServerPagination } from './analytics-api.models';

export interface ReportGestion {
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
  puntaje: number;
}

export interface ReportPayment {
  id: number;
  fechaPago: string;
  codigo: string;
  dni: string | null;
  monto: number;
  ejecutivo: string | null;
  tipoAcuerdo: string | null;
}

export interface ReportSummary { gestiones?: number; pagos?: number; clientes: number; monto: number; }
export interface ReportResponse<T> { data: T[]; summary: ReportSummary; pagination: ServerPagination; }
export interface GestionReportOptions { asesores: string[]; cosechas: string[]; carteras: string[]; nivel1: string[]; nivel2: string[]; }
export interface PaymentReportOptions { ejecutivos: string[]; acuerdos: string[]; }

export interface GestionReportFilters {
  fechaDesde: string;
  fechaHasta: string;
  dni: string;
  asesor: string[];
  cosecha: string[];
  cartera: string[];
  nivel1: string[];
  nivel2: string[];
  telefono: string;
  search: string;
}

export interface PaymentReportFilters {
  fechaDesde: string;
  fechaHasta: string;
  codigo: string;
  dni: string;
  ejecutivo: string;
  tipoAcuerdo: string[];
  search: string;
}
