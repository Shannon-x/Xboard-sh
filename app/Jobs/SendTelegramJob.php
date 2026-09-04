<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected string $parseMode;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @param string $parseMode 'markdown'（旧行为，整段自动转义）| 'html'（模板已自行转义动态值）| ''（纯文本）
     * @return void
     */
    public function __construct(int $telegramId, string $text, string $parseMode = 'markdown')
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->parseMode = $parseMode;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $telegramService = new TelegramService();
        $telegramService->sendMessage($this->telegramId, $this->text, $this->parseMode);
    }
}
