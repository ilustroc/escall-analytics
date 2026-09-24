import { InjectionToken } from '@angular/core';
import { GestionExpertis, Nivel1, PagoExpertis, Tipificacion } from '../models/analytics.models';
/** Replace token providers in app.config when the REST adapter is introduced. */
export interface ExpertisDataSource {
  readonly gestiones: readonly GestionExpertis[];
  readonly asesores: readonly string[];
  readonly cosechas: readonly string[];
  readonly equipos: readonly string[];
  readonly niveles: readonly Nivel1[];
  readonly tipificaciones: readonly Tipificacion[];
}
export interface PaymentsDataSource { readonly pagos: readonly PagoExpertis[]; }
export const EXPERTIS_SOURCE = new InjectionToken<ExpertisDataSource>('EXPERTIS_SOURCE');
export const PAYMENTS_SOURCE = new InjectionToken<PaymentsDataSource>('PAYMENTS_SOURCE');
