<?php

namespace Modules\WebPush\Console;

use Illuminate\Console\Command;
use Modules\WebPush\Services\Health as HealthCheck;

/** php artisan webpush:health [--json]: exit code 0 when Web Push is healthy, 1 otherwise (Services\Health). */
class Health extends Command
{
    protected $signature = 'webpush:health {--json : Print the report as JSON}';

    protected $description = 'Checks that Web Push is complete and able to send (for updaters: exit code 0 or 1)';

    public function handle()
    {
        $report = HealthCheck::check();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Web Push '.$report['version'].': '.($report['ok'] ? 'healthy' : 'NOT healthy'));
            foreach ($report['checks'] as $name => $check) {
                $this->line(sprintf('  %-12s %s', $name, $check['ok'] ? 'ok' : 'FAILED - '.$check['error']));
            }
        }
        return $report['ok'] ? 0 : 1;
    }
}
