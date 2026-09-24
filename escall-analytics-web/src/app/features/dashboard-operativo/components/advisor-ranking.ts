import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { AdvisorRanking as RankingRow } from '../../../core/models/analytics.models';
import {
  formatDecimal,
  formatMoney,
  formatNumber,
  formatPercent,
  shortAdvisor,
} from '../../../core/utils/format';
import { Icon } from '../../../shared/components/icon/icon';
@Component({
  selector: 'app-advisor-ranking',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<section class="detail-panel ranking-panel">
    <header class="detail-header">
      <div>
        <h2>Ranking de asesores</h2>
        <p>Productividad y resultados del día seleccionado</p>
      </div>
      <span class="chart-unit"
        ><app-icon name="Users" [size]="13" />{{ rows().length }} asesores · por gestiones ↓</span
      >
    </header>
    <div class="ranking-scroll">
      <table class="ranking-table">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">Asesor</th>
            <th scope="col">Gestiones</th>
            <th scope="col">Clientes</th>
            <th scope="col">Contactados</th>
            <th scope="col" title="Clientes con contacto efectivo">CE</th>
            <th scope="col">CE %</th>
            <th scope="col" title="Clientes con mejor resultado PPC">PPC</th>
            <th scope="col">Monto Promesa</th>
            <th scope="col" title="Clientes únicos con pago">Pagos</th>
            <th scope="col">Monto Pago</th>
            <th scope="col">Intensidad</th>
          </tr>
        </thead>
        <tbody>
          @for (row of rows(); track row.asesor) {
            <tr>
              <td>
                <span class="rank-number" [class.top-rank]="row.posicion <= 3">{{
                  row.posicion
                }}</span>
              </td>
              <th scope="row">
                <span class="advisor-avatar"
                  >{{ row.asesor.charAt(0) }}{{ row.asesor.split(' ')[1].charAt(0) }}</span
                >{{ name(row.asesor) }}
              </th>
              <td class="font-semibold">{{ number(row.gestiones) }}</td>
              <td>{{ row.clientes }}</td>
              <td>{{ row.contactados }}</td>
              <td>{{ row.efectivos }}</td>
              <td>
                <div class="table-percent">
                  <span>{{ percent(row.contactoEfectivo) }}</span
                  ><i><b [style.width.%]="row.contactoEfectivo"></b></i>
                </div>
              </td>
              <td>
                <span class="ppc-number">{{ row.ppc }}</span>
              </td>
              <td>{{ money(row.montoComprometido) }}</td>
              <td>{{ row.clientesPago }}</td>
              <td>{{ money(row.montoPagado) }}</td>
              <td>{{ decimal(row.intensidad) }}</td>
            </tr>
          }
        </tbody>
      </table>
    </div>
    <div class="table-caption">
      CE: contacto efectivo · PPC: mejor resultado por cliente · Pagos: reales pendientes de
      integración
    </div>
  </section>`,
})
export class AdvisorRanking {
  readonly rows = input.required<RankingRow[]>();
  readonly number = formatNumber;
  readonly money = formatMoney;
  readonly decimal = formatDecimal;
  readonly percent = formatPercent;
  readonly name = shortAdvisor;
}
