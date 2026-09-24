import { GestionExpertis, PagoExpertis } from '../core/models/analytics.models';
import { seededRandom } from '../core/utils/seed';
export function generatePayments(gestiones: readonly GestionExpertis[], seed = 314159): PagoExpertis[] {
  const random = seededRandom(seed);
  return gestiones.filter(row => row.fechaCompromiso !== null && row.monto > 0)
    .filter(() => random() < 0.72)
    .map((row, index) => ({ id: 8001 + index, dni: row.dni,
      fechaPago: row.fechaCompromiso ?? row.fechaLlamada,
      montoPago: Math.round(row.monto * (random() < 0.75 ? 1 : 0.5) * 100) / 100,
    }));
}
