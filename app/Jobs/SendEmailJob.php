<?php

namespace App\Jobs;

use App\Jobs\Middleware\MailLaneThrottle;
use App\Services\Mail\DeliveryMonitor;
use App\Services\Mail\MailLane;
use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;

    /**
     * @param array $params 见 MailService::sendEmail；即时通道可带 not_after（时间戳）：排到时已过期就不发
     * @param string $queue 通道，MailLane::INSTANT / TRANSACTIONAL / BULK（旧名 send_email_mass 记作 BULK）。
     *   不传 = 交易类：没想清楚的新调用方最多影响收据，碰不到验证码
     */
    public function __construct($params, $queue = MailLane::TRANSACTIONAL)
    {
        $lane = MailLane::normalize($queue);
        $this->onQueue($lane);
        $this->params = $params;
        if ($lane === MailLane::BULK) {
            // 批量限速开着时 worker 可能要等配额（MailLaneThrottle 最多阻塞 20 秒），10 秒会被杀
            $this->timeout = 60;
        }
    }

    public function middleware(): array
    {
        return [new MailLaneThrottle()];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->expired()) {
            return;
        }
        $mailLog = MailService::sendEmail($this->params, MailLane::normalize($this->queue));
        if ($mailLog['error'] && DeliveryMonitor::isRetryable($mailLog['category'] ?? DeliveryMonitor::TEMPORARY)) {
            $this->release(); // 临时失败 / SMTP 配置问题才重试；退信重试只会再退一次
        }
    }

    /**
     * 即时邮件排到时已经过了有效期（验证码、登录链接都是 300 秒）：发出去也只是一封废码，
     * 用户界面本来就能重新获取。记一条 expired 日志，不计入用户的投递失败。
     */
    private function expired(): bool
    {
        $notAfter = (int) ($this->params['not_after'] ?? 0);
        if ($notAfter <= 0 || time() <= $notAfter || !config('mail_lanes.drop_expired_instant', true)) {
            return false;
        }
        DeliveryMonitor::recordExpired(
            (string) $this->params['email'],
            (string) ($this->params['subject'] ?? ''),
            'mail.' . admin_setting('email_template', 'default') . '.' . ($this->params['template_name'] ?? ''),
            time() - $notAfter,
            isset($this->params['user_id']) ? (int) $this->params['user_id'] : null
        );
        return true;
    }
}
