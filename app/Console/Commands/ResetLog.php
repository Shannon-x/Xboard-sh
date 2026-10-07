<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\MailLog;
use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Console\Command;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        StatUser::where('record_at', '<', strtotime('-2 month', time()))->delete();
        StatServer::where('record_at', '<', strtotime('-2 month', time()))->delete();
        AdminAuditLog::where('created_at', '<', strtotime('-3 month', time()))->delete();

        // 邮件投递日志：每天的到期 / 流量提醒都会记一行，按后台配置的保留期分批删（0 = 永久保留）
        $days = max(0, min(3650, (int) admin_setting('mail_log_retention_days', 180)));
        if ($days > 0) {
            $cutoff = time() - $days * 86400;
            do {
                $deleted = MailLog::where('created_at', '<', $cutoff)->limit(5000)->delete();
            } while ($deleted >= 5000);
        }
    }
}
