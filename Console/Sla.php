<?php

namespace Modules\WebPush\Console;

use Illuminate\Console\Command;
use Modules\WebPush\Services\Sla as SlaAlerts;

/** php artisan webpush:sla: sends the SLA alerts that are due (Services\Sla). Run every five minutes by the scheduler. */
class Sla extends Command
{
    protected $signature = 'webpush:sla';

    protected $description = 'Sends Web Push alerts for open tickets about to miss their SLA target';

    public function handle()
    {
        if (!SlaAlerts::targets()) {
            $this->line('No SLA targets (no module answers fsinterop.sla.targets): nothing to do.');
            return 0;
        }
        $this->line('SLA alerts sent: '.SlaAlerts::run());
        return 0;
    }
}
