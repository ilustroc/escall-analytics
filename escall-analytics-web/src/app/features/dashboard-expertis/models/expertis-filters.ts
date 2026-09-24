import { AnalyticsFilters } from '../../../core/models/analytics.models';
import { currentMonthStartLocalIsoDate, todayLocalIsoDate } from '../../../core/utils/local-date';
export const createExpertisFilters = (): AnalyticsFilters => ({
  desde: currentMonthStartLocalIsoDate(),
  hasta: todayLocalIsoDate(),
  asesores: [],
  cosechas: [],
  nivel1: [],
  nivel2: [],
  equipos: [],
});
