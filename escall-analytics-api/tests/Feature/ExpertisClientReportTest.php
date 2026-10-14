<?php

namespace Tests\Feature;

use App\Jobs\SendExpertisClientReportJob;
use App\Mail\ExpertisClientReportMail;
use App\Models\AnalyticsAuditLog;
use App\Services\Analytics\AnalyticsAuditService;
use App\Services\Analytics\ExpertisClientXlsxExportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class ExpertisClientReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('analytics.expertis_report.to', ['alejandro.romero@goexpertis.com', 'Gina.Georffino@goexpertis.com']);
        config()->set('analytics.expertis_report.cc', ['katherine.supervision@escallperu.com', 'cespinozag@escallperu.com']);
    }

    public function test_job_sends_only_to_client_report_recipients_without_cc(): void
    {
        Mail::fake();
        $xlsx = Mockery::mock(ExpertisClientXlsxExportService::class);
        $xlsx->shouldReceive('createPrivateReport')->once()->andReturn($this->report());
        $xlsx->shouldReceive('delete')->once();

        (new SendExpertisClientReportJob('2026-10-14'))->handle($xlsx, app(AnalyticsAuditService::class));

        Mail::assertSent(ExpertisClientReportMail::class, function (ExpertisClientReportMail $mail): bool {
            $mail->assertTo('katherine.supervision@escallperu.com');
            $mail->assertTo('cespinozag@escallperu.com');

            return collect($mail->to)->pluck('address')->all() === [
                'katherine.supervision@escallperu.com',
                'cespinozag@escallperu.com',
            ] && $mail->cc === [];
        });
        $audit = AnalyticsAuditLog::query()->where('action', 'expertis_client_report_sent')->firstOrFail();
        $this->assertSame(['katherine.supervision@escallperu.com', 'cespinozag@escallperu.com'], $audit->metadata['to']);
        $this->assertSame([], $audit->metadata['cc']);
    }

    public function test_send_command_queues_the_client_report_without_sending_email(): void
    {
        Queue::fake();
        Mail::fake();

        $this->artisan('analytics:expertis-client-report-email', ['--send' => true, '--date' => '2026-10-14'])->assertSuccessful();

        Queue::assertPushed(SendExpertisClientReportJob::class, static fn (SendExpertisClientReportJob $job): bool => $job->reportDate === '2026-10-14' && $job->force === false && $job->queue === 'reports');
        Mail::assertNothingSent();
    }

    public function test_dry_run_displays_only_client_report_recipients(): void
    {
        Mail::fake();
        $xlsx = Mockery::mock(ExpertisClientXlsxExportService::class);
        $xlsx->shouldReceive('createPrivateReport')->once()->andReturn($this->report());
        $xlsx->shouldReceive('delete')->once();
        $this->app->instance(ExpertisClientXlsxExportService::class, $xlsx);

        $this->artisan('analytics:expertis-client-report-email', ['--dry-run' => true, '--date' => '2026-10-14'])
            ->expectsOutputToContain('katherine.supervision@escallperu.com, cespinozag@escallperu.com')
            ->doesntExpectOutputToContain('alejandro.romero@goexpertis.com')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    private function report(): array
    {
        return ['path' => 'reports/expertis/test.xlsx', 'filename' => 'test.xlsx', 'rows' => 0, 'range' => 'Sheet1!$A$1:$Z$1', 'subject' => 'Reporte de Cartera – Expertis | 14 de octubre de 2026', 'periodLabel' => CarbonImmutable::parse('2026-10-14')->format('d de octubre de Y')];
    }
}
