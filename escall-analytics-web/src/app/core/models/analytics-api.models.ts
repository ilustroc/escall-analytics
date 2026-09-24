import { GestionExpertis } from './analytics.models';

export interface AnalyticsFilterOptions {
  asesores: string[];
  equipos: string[];
  cosechas: string[];
  nivel1: string[];
  nivel2: string[];
}

export interface ExpertisApiKpis {
  gestiones: number;
  clientesGestionados: number;
  clientesContactados: number;
  contactabilidad: number;
  clientesContactoEfectivo: number;
  contactoEfectivo: number;
  intensidad: number;
  clientesCompromiso: number;
  montoComprometido: number;
}

export interface ExpertisDashboardResponse {
  kpis: ExpertisApiKpis;
  pagos: { clientes: number; cuentas?: number; monto: number; cumplimiento: number; cantidadPagos?: number };
  pagosEvolucion?: Array<{ fecha: string; cantidad: number; clientes: number; monto: number }>;
  pagosPorTipo?: Array<{ tipo: string; cantidad: number; monto: number }>;
  rankingPagos?: Array<{ asesor: string; pagos: number; monto: number }>;
  evolucionDiaria: Array<{ fecha: string; gestiones: number; clientes: number }>;
  nivel1: Array<{ nivel: string; clientes: number }>;
}

export interface ServerPagination {
  page: number;
  perPage: number;
  total: number;
  lastPage: number;
}

export interface ExpertisGestionesResponse {
  data: GestionExpertis[];
  pagination: ServerPagination;
}

export interface OperativoApiKpis {
  gestiones: number;
  clientesGestionados: number;
  clientesContactados: number;
  contactoEfectivo: number;
  contactoEfectivoPorcentaje: number;
  ppc: number;
  montoComprometido: number;
  clientesConPago: number;
  montoPagado: number;
}

export interface OperativoDashboardResponse {
  kpis: OperativoApiKpis;
  rankingAsesores: Array<{
    asesor: string; gestiones: number; clientes: number; contactados: number; contactoEfectivo: number;
    contactoEfectivoPorcentaje: number; ppc: number; montoPromesa: number; pagos: number; montoPago: number; intensidad: number;
  }>;
  actividadHoraria: Array<{ hora: string; gestiones: number; contactados: number; contactoEfectivo: number; ppc: number }>;
  contactoEfectivoAsesor: Array<{ asesor: string; clientes: number; contactoEfectivo: number; porcentaje: number }>;
  ppcAsesor: Array<{ asesor: string; ppc: number }>;
  actividadReciente: Array<{ hora: string | null; asesor: string; dni: string; nivel1: string; nivel2: string; monto: number }>;
}
