import {
  AdvisorRanking,
  AnalyticsFilters,
  DailyEvolution,
  DashboardMetrics,
  Distribution,
  GestionExpertis,
  HourlyActivity,
  PagoExpertis,
  Tipificacion,
} from '../models/analytics.models';
export const ratio = (numerator: number, denominator: number) =>
  denominator > 0 ? numerator / denominator : 0;
export const getDistinctClients = (rows: readonly GestionExpertis[]): Set<string> =>
  new Set(rows.map((row) => row.dni));
export function getBestResultPerClient(
  rows: readonly GestionExpertis[],
  catalog: readonly Tipificacion[],
): GestionExpertis[] {
  const weights = new Map<string, number>(catalog.map((tip) => [tip.codigo, tip.peso]));
  const best = new Map<string, GestionExpertis>();
  for (const row of rows) {
    const previous = best.get(row.dni);
    const weight = weights.get(row.nivel2) ?? Infinity;
    const previousWeight = previous ? (weights.get(previous.nivel2) ?? Infinity) : Infinity;
    if (
      !previous ||
      weight < previousWeight ||
      (weight === previousWeight &&
        `${row.fechaLlamada}${row.hora ?? ''}` > `${previous.fechaLlamada}${previous.hora ?? ''}`)
    )
      best.set(row.dni, row);
  }
  return [...best.values()];
}
export const calculateContactability = (consolidated: readonly GestionExpertis[]) =>
  ratio(consolidated.filter((row) => row.nivel1 !== 'NO CONTACTO').length, consolidated.length) *
  100;
export const calculateEffectiveContact = (consolidated: readonly GestionExpertis[]) =>
  ratio(
    consolidated.filter((row) => row.nivel1 === 'CONTACTO EFECTIVO').length,
    consolidated.length,
  ) * 100;
export const calculateIntensity = (rows: readonly GestionExpertis[]) =>
  ratio(rows.length, getDistinctClients(rows).size);
export function filterGestiones(
  rows: readonly GestionExpertis[],
  filters: AnalyticsFilters,
): GestionExpertis[] {
  return rows.filter(
    (row) =>
      (!filters.desde || row.fechaLlamada >= filters.desde) &&
      (!filters.hasta || row.fechaLlamada <= filters.hasta) &&
      (!filters.asesores.length || filters.asesores.includes(row.asesor)) &&
      (!filters.cosechas.length || filters.cosechas.includes(row.cosecha ?? '')) &&
      (!filters.nivel1.length || filters.nivel1.includes(row.nivel1)) &&
      (!filters.nivel2.length || filters.nivel2.includes(row.nivel2)) &&
      (!filters.equipos.length || filters.equipos.includes(row.equipo)),
  );
}
export function calculatePayments(
  payments: readonly PagoExpertis[],
  clients: ReadonlySet<string>,
  desde: string,
  hasta: string,
) {
  const selected = payments.filter(
    (row) =>
      clients.has(row.dni) &&
      (!desde || row.fechaPago >= desde) &&
      (!hasta || row.fechaPago <= hasta) &&
      Number.isFinite(row.montoPago) &&
      row.montoPago > 0,
  );
  return {
    clientesPago: new Set(selected.map((row) => row.dni)).size,
    montoPagado: selected.reduce((sum, row) => sum + row.montoPago, 0),
  };
}
export function calculateMetrics(
  rows: readonly GestionExpertis[],
  payments: readonly PagoExpertis[],
  filters: Pick<AnalyticsFilters, 'desde' | 'hasta'>,
  catalog: readonly Tipificacion[],
): DashboardMetrics {
  const best = getBestResultPerClient(rows, catalog);
  const promises = rows.filter(
    (row) => row.fechaCompromiso && Number.isFinite(row.monto) && row.monto > 0,
  );
  const montoComprometido = promises.reduce((sum, row) => sum + row.monto, 0);
  const paymentMetrics = calculatePayments(
    payments,
    getDistinctClients(rows),
    filters.desde,
    filters.hasta,
  );
  return {
    gestiones: rows.length,
    clientes: best.length,
    contactados: best.filter((row) => row.nivel1 !== 'NO CONTACTO').length,
    efectivos: best.filter((row) => row.nivel1 === 'CONTACTO EFECTIVO').length,
    contactabilidad: calculateContactability(best),
    contactoEfectivo: calculateEffectiveContact(best),
    intensidad: calculateIntensity(rows),
    compromisos: getDistinctClients(rows.filter((row) => row.fechaCompromiso !== null)).size,
    montoComprometido,
    ...paymentMetrics,
    cumplimiento: ratio(paymentMetrics.montoPagado, montoComprometido) * 100,
    ppc: best.filter((row) => row.nivel2 === 'PPC').length,
  };
}
export function calculateDailyEvolution(
  rows: readonly GestionExpertis[],
  desde: string,
  hasta: string,
): DailyEvolution[] {
  if (!rows.length) return [];
  const grouped = new Map<string, GestionExpertis[]>();
  for (const row of rows)
    grouped.set(row.fechaLlamada, [...(grouped.get(row.fechaLlamada) ?? []), row]);
  const dates = [...grouped.keys()].sort();
  // UTC iteration avoids day shifts across time zones. No unrelated blank years in charts.
  const start = new Date(`${desde || dates[0]}T00:00:00Z`);
  const end = new Date(`${hasta || dates[dates.length - 1]}T00:00:00Z`);
  const result: DailyEvolution[] = [];
  if ((end.getTime() - start.getTime()) / 86400000 > 366)
    return dates.map((fecha) => ({
      fecha,
      gestiones: grouped.get(fecha)!.length,
      clientes: getDistinctClients(grouped.get(fecha)!).size,
    }));
  for (let date = start; date <= end; date = new Date(date.getTime() + 86400000)) {
    const fecha = date.toISOString().slice(0, 10);
    const group = grouped.get(fecha) ?? [];
    result.push({ fecha, gestiones: group.length, clientes: getDistinctClients(group).size });
  }
  return result;
}
export function calculateIntensityDistribution(rows: readonly GestionExpertis[]): Distribution[] {
  const counts = new Map<string, number>();
  for (const row of rows) counts.set(row.dni, (counts.get(row.dni) ?? 0) + 1);
  return ['1 gestión', '2 gestiones', '3 gestiones', '4 gestiones', '5+ gestiones'].map(
    (name, index) => ({
      name,
      value: [...counts.values()].filter((count) =>
        index === 4 ? count >= 5 : count === index + 1,
      ).length,
    }),
  );
}
export function calculateAdvisorRanking(
  rows: readonly GestionExpertis[],
  payments: readonly PagoExpertis[],
  filters: AnalyticsFilters,
  catalog: readonly Tipificacion[],
): AdvisorRanking[] {
  return [...new Set(rows.map((row) => row.asesor))]
    .map((asesor) => ({
      asesor,
      posicion: 0,
      ...calculateMetrics(
        rows.filter((row) => row.asesor === asesor),
        payments,
        filters,
        catalog,
      ),
    }))
    .sort((a, b) => b.gestiones - a.gestiones || a.asesor.localeCompare(b.asesor))
    .map((row, index) => ({ ...row, posicion: index + 1 }));
}
export function calculateHourlyActivity(rows: readonly GestionExpertis[]): HourlyActivity[] {
  return Array.from({ length: 11 }, (_, index) => {
    const hora = `${String(index + 8).padStart(2, '0')}:00`;
    const group = rows.filter((row) => row.hora?.slice(0, 2) === hora.slice(0, 2));
    return {
      hora,
      gestiones: group.length,
      contactos: group.filter((row) => row.nivel1 !== 'NO CONTACTO').length,
      efectivos: group.filter((row) => row.nivel1 === 'CONTACTO EFECTIVO').length,
      ppc: group.filter((row) => row.nivel2 === 'PPC').length,
    };
  });
}
