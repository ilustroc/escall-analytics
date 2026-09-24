import { GestionExpertis } from '../core/models/analytics.models';
import { seededRandom } from '../core/utils/seed';
import { GESTION_NIVEL, TIPIFICACIONES } from './tipificaciones.mock';
export const ASESORES = ['CATHERINE VEGA', 'DIANA MUNOZ', 'DIEGO SANCHEZ', 'EVELYN SANTAMARIA', 'GRISEL CARRIAZO', 'KATHERINE HUAMAN', 'NAYKE GUTIERREZ', 'SHIRLEY CASTILLO', 'YOHAN TELLES'];
export const COSECHAS = ['COSECHA OH 19/12', 'COSECHA LAN 23/12', 'COSECHA TRU 22/12', 'COSECHA OH 23/06', 'COSECHA LAN 22/11', 'COSECHA TRU 21/05', 'COSECHA OH 20/09'];
const NOMBRES = ['MARIA ELENA', 'JOSE LUIS', 'ANA LUCIA', 'LUIS ALBERTO', 'ROSA ISABEL', 'JORGE ENRIQUE', 'CARMEN ROSA', 'PEDRO MIGUEL', 'LUCIA PATRICIA', 'MIGUEL ANGEL', 'SANDRA MILAGROS', 'VICTOR MANUEL'];
const APELLIDOS = ['QUISPE RAMIREZ', 'FLORES VARGAS', 'TORRES MENDOZA', 'GARCIA HUAMAN', 'ROJAS CASTILLO', 'SANCHEZ PAREDES', 'DIAZ SALAZAR', 'RAMOS VASQUEZ', 'CHAVEZ GUTIERREZ', 'MEDINA AGUILAR', 'ESPINOZA RUIZ', 'PEREZ CARRASCO'];
const pad = (value: number) => String(value).padStart(2, '0');
export function generateExpertisData(seed = 20260914): GestionExpertis[] {
  const random = seededRandom(seed);
  const records: GestionExpertis[] = [];
  for (let client = 0; client < 108; client++) {
    const attempts = [1, 2, 2, 3, 5][client % 5];
    const firstDay = 1 + Math.floor(random() * 8);
    for (let attempt = 0; attempt < attempts; attempt++) {
      const last = attempt === attempts - 1;
      const finalCodes = [0, 0, 0, 1, 2, 3, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 15, 15, 16];
      const codeIndex = last ? finalCodes[Math.floor(random() * finalCodes.length)] : 9 + Math.floor(random() * 8);
      const tip = TIPIFICACIONES[codeIndex];
      const day = last && client % 4 !== 0 ? 14 : Math.min(13, firstDay + attempt);
      const compromiso = last && tip.peso <= 3;
      const monto = compromiso ? [250, 350, 400, 500, 650, 800, 1000, 1200][Math.floor(random() * 8)] : 0;
      records.push({
        idGestion: 1902700 + records.length,
        canalGestion: 'ESCALL', canalAsignacion: 'ESCALL',
        dni: String(40100200 + client * 139),
        nombreCliente: `${NOMBRES[client % NOMBRES.length]} ${APELLIDOS[Math.floor(client / 3) % APELLIDOS.length]}`,
        cartera: 'EXPERTIS', asesor: ASESORES[client % ASESORES.length], equipo: 'KATHERINE HUAMAN',
        telefono: String(910200300 + client * 217), fechaLlamada: `2026-09-${pad(day)}`,
        cosecha: COSECHAS[client % COSECHAS.length],
        hora: `${pad(8 + Math.floor(random() * 11))}:${pad(Math.floor(random() * 60))}:${pad(Math.floor(random() * 60))}`,
        nivel1: GESTION_NIVEL[tip.gestion], nivel2: tip.codigo,
        fechaCompromiso: compromiso ? `2026-09-${pad(Math.min(18, day + Math.floor(random() * 3)))}` : null,
        monto,
        observacion: compromiso ? `Titular confirma abono de S/ ${monto}. Se envían los medios de pago y se programa seguimiento.` : `${tip.descripcion}. ${tip.gestion === 'NOC' ? 'Se programa nuevo intento en otra franja horaria.' : 'Se actualiza el seguimiento de la cartera.'}`,
        medioGestion: 'MANUAL',
      });
    }
  }
  records.push({ idGestion: 1902684, canalGestion: 'ESCALL', canalAsignacion: 'ESCALL', dni: '22518749', nombreCliente: 'CARLOS DARWIN NOLBERTO AGUIRRE', cartera: 'EXPERTIS', asesor: 'SHIRLEY CASTILLO', equipo: 'KATHERINE HUAMAN', telefono: '917064274', fechaLlamada: '2026-09-07', cosecha: 'COSECHA OH 19/12', hora: '08:07:33', nivel1: 'CONTACTO EFECTIVO', nivel2: 'PPC', fechaCompromiso: '2026-09-07', monto: 400, observacion: 'TT pagará. Confirma pago por agente bancario.', medioGestion: 'MANUAL' });
  return records.sort((a, b) => `${b.fechaLlamada} ${b.hora ?? ''}`.localeCompare(`${a.fechaLlamada} ${a.hora ?? ''}`));
}
export const EXPERTIS_MOCK: readonly GestionExpertis[] = generateExpertisData();
