import { Injectable } from '@angular/core';
import { ASESORES, COSECHAS, EXPERTIS_MOCK } from '../../mock/expertis.mock';
import { NIVEL_1, TIPIFICACIONES } from '../../mock/tipificaciones.mock';
@Injectable({ providedIn: 'root' })
export class ExpertisMockService {
  readonly gestiones = EXPERTIS_MOCK;
  readonly asesores = ASESORES;
  readonly cosechas = COSECHAS;
  readonly equipos = ['KATHERINE HUAMAN'];
  readonly niveles = NIVEL_1;
  readonly tipificaciones = TIPIFICACIONES;
}
