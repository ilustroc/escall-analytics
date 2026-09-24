import { AnalyticsFilters } from '../../../core/models/analytics.models';
import { todayLocalIsoDate } from '../../../core/utils/local-date';
export const createOperativoFilters = (): AnalyticsFilters => ({
  desde: todayLocalIsoDate(),
  hasta: todayLocalIsoDate(),
  asesores: [],
  cosechas: [],
  nivel1: [],
  nivel2: [],
  equipos: [],
});
