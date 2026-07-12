<?php

namespace App\Console\Commands;

use App\Services\DeviceStateService;
use Illuminate\Console\Command;

class ReconcileDeviceOnlineCounts extends Command
{
    protected $signature = 'device:reconcile-online-counts {--chunk=500 : Database rows per Redis pipeline}';

    protected $description = 'Reconcile v2_user.online_count with real-time Redis device state';

    public function handle(DeviceStateService $deviceStateService): int
    {
        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5000],
        ]);

        if ($chunkSize === false) {
            $this->error('The --chunk option must be an integer between 1 and 5000.');
            return self::INVALID;
        }

        $updated = $deviceStateService->reconcileOnlineCounts($chunkSize);
        $this->info("Reconciled {$updated} user online count(s).");

        return self::SUCCESS;
    }
}
