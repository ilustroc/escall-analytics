import { inject, Injectable } from '@angular/core';
import { generatePayments } from '../../mock/pagos.mock';
import { ExpertisMockService } from './expertis-mock.service';
@Injectable({ providedIn: 'root' })
export class PagosMockService {
  readonly pagos = generatePayments(inject(ExpertisMockService).gestiones);
}
