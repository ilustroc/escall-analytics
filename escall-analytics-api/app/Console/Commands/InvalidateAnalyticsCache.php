<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsCache;
use Illuminate\Console\Command;

final class InvalidateAnalyticsCache extends Command
{
    protected $signature = 'analytics:cache:invalidate';

    protected $description = 'Invalidates cached ESCALL Analytics responses.';

    public function handle(AnalyticsCache $cache): int
    {
        $cache->invalidate();
        $this->components->info('Analytics cache invalidated.');

        return self::SUCCESS;
    }
}
