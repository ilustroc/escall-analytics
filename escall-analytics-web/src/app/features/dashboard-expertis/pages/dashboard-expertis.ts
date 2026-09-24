import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { DashboardExpertisService } from '../services/dashboard-expertis.service';
import { formatDecimal, formatMoney, formatNumber, formatPercent } from '../../../core/utils/format';
import { KpiCard } from '../../../shared/components/kpi-card/kpi-card';
import { PageHeader } from '../../../shared/components/page-header/page-header';
import { LoadingSkeleton } from '../../../shared/components/loading-skeleton/loading-skeleton';
import { EmptyState } from '../../../shared/components/empty-state/empty-state';
import { Icon } from '../../../shared/components/icon/icon';
import { ExpertisFilters } from '../components/expertis-filters';
import { ExpertisCharts } from '../components/expertis-charts';
import { GestionGrid } from '../components/gestion-grid';
@Component({ selector: 'app-dashboard-expertis', imports: [KpiCard, PageHeader, LoadingSkeleton, EmptyState, Icon, ExpertisFilters, ExpertisCharts, GestionGrid], providers: [DashboardExpertisService], templateUrl: './dashboard-expertis.html', changeDetection: ChangeDetectionStrategy.OnPush })
export class DashboardExpertis {
  readonly data = inject(DashboardExpertisService);
  readonly number = formatNumber; readonly money = formatMoney; readonly percent = formatPercent; readonly decimal = formatDecimal;
}
