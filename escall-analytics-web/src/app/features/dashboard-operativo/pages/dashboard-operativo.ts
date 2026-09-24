import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { DashboardOperativoService } from '../services/dashboard-operativo.service';
import { formatDate, formatMoney, formatNumber, formatPercent } from '../../../core/utils/format';
import { PageHeader } from '../../../shared/components/page-header/page-header';
import { KpiCard } from '../../../shared/components/kpi-card/kpi-card';
import { LoadingSkeleton } from '../../../shared/components/loading-skeleton/loading-skeleton';
import { EmptyState } from '../../../shared/components/empty-state/empty-state';
import { Icon } from '../../../shared/components/icon/icon';
import { OperativoFilters } from '../components/operativo-filters';
import { AdvisorRanking } from '../components/advisor-ranking';
import { OperativoCharts } from '../components/operativo-charts';
import { RecentActivity } from '../components/recent-activity';
@Component({ selector: 'app-dashboard-operativo', providers: [DashboardOperativoService], imports: [PageHeader, KpiCard, LoadingSkeleton, EmptyState, Icon, OperativoFilters, AdvisorRanking, OperativoCharts, RecentActivity], templateUrl: './dashboard-operativo.html', changeDetection: ChangeDetectionStrategy.OnPush })
export class DashboardOperativo {
  readonly data = inject(DashboardOperativoService);
  readonly number = formatNumber; readonly money = formatMoney; readonly percent = formatPercent; readonly date = formatDate;
}
