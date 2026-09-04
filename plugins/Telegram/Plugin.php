<?php

namespace Plugin\Telegram;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\TicketAttachmentService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
  /** 通知里工单正文的展示上限（Telegram 单条 4096 字符，还要给账号信息留位置） */
  private const NOTIFY_CONTENT_LIMIT = 2500;

  protected array $commands = [];
  protected TelegramService $telegramService;

  protected array $commandConfigs = [
    '/start' => ['description' => '开始使用', 'handler' => 'handleStartCommand'],
    '/bind' => ['description' => '绑定账号', 'handler' => 'handleBindCommand'],
    '/traffic' => ['description' => '查看流量', 'handler' => 'handleTrafficCommand'],
    '/getlatesturl' => ['description' => '获取订阅链接', 'handler' => 'handleGetLatestUrlCommand'],
    '/unbind' => ['description' => '解绑账号', 'handler' => 'handleUnbindCommand'],
  ];

  public function boot(): void
  {
    $this->telegramService = new TelegramService();
    $this->registerDefaultCommands();

    $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
    $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
    $this->listen('telegram.message.error', [$this, 'handleError'], 10);
    $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
    $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
  }

  public function sendPaymentNotify(Order $order): void
  {
    if (!$this->getConfig('enable_payment_notify', true)) {
      return;
    }

    $payment = $order->payment;
    if (!$payment) {
      Log::warning('支付通知失败：订单关联的支付方式不存在', ['order_id' => $order->id]);
      return;
    }

    $e = [TelegramService::class, 'escapeHtml'];
    $message = sprintf(
      "💰 <b>成功收款 %s 元</b>\n" .
      "———————————————\n" .
      "支付接口：%s\n" .
      "支付渠道：%s\n" .
      "本站订单：<code>%s</code>",
      $order->total_amount / 100,
      $e((string) $payment->payment),
      $e((string) $payment->name),
      $e((string) $order->trade_no)
    );
    $this->telegramService->sendMessageWithAdmin($message, true, 'html');
  }

  /**
   * 工单创建 / 用户回复 → 推给管理员与客服。
   *
   * 用 HTML parse_mode 并只转义动态值：之前整段走旧版 Markdown 自动转义，把模板里的 *粗体* 和 `代码`
   * 标记也一起转义掉了，管理员看到的是带星号反引号的原始字符。附件另起队列任务逐个推送图片 / 文件。
   */
  public function sendTicketNotify(Ticket $ticket): void
  {
    if (!$this->getConfig('enable_ticket_notify', true)) {
      return;
    }

    /** @var TicketMessage|null $message */
    $message = $ticket->messages()->with('attachments')->latest('id')->first();
    $user = User::find($ticket->user_id);
    if (!$user || !$message) {
      return;
    }
    $user->load('plan');
    $e = [TelegramService::class, 'escapeHtml'];

    $transfer_enable = $this->transferToGBString($user->transfer_enable);
    $remaining_traffic = $this->transferToGBString($user->transfer_enable - $user->u - $user->d);
    $u = $this->transferToGBString($user->u);
    $d = $this->transferToGBString($user->d);
    $expired_at = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
    $money = $user->balance / 100;
    $affmoney = $user->commission_balance / 100;
    $plan = $user->plan;
    $ip = request()?->ip() ?? '';
    $region = $ip ? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (new \Ip2Region())->simple($ip) : 'NULL') : '';

    $attachments = $message->attachments;
    $content = trim((string) $message->message);
    if ($content === '') {
      $content = $attachments->isNotEmpty() ? '（没有文字，请看附件）' : '（空）';
    }
    $content = TelegramService::truncate($content, self::NOTIFY_CONTENT_LIMIT);

    $isReply = $ticket->messages()->count() > 1;
    $TGmessage = ($isReply ? "📮 <b>工单回复</b> #{$ticket->id}\n" : "📮 <b>工单提醒</b> #{$ticket->id}\n");
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📧 邮箱: <code>{$e($user->email)}</code>\n";
    $TGmessage .= "📍 位置: <code>{$e((string) $region)}</code>\n";

    if ($plan) {
      $TGmessage .= "📦 套餐: <code>{$e((string) $plan->name)}</code>\n";
      $TGmessage .= "📊 流量: <code>{$remaining_traffic}G / {$transfer_enable}G</code> (剩余/总计)\n";
      $TGmessage .= "⬆️⬇️ 已用: <code>{$u}G / {$d}G</code>\n";
      $TGmessage .= "⏰ 到期: <code>{$expired_at}</code>\n";
    } else {
      $TGmessage .= "📦 套餐: <code>未订购任何套餐</code>\n";
    }

    $TGmessage .= "💰 余额: <code>{$money}元</code>\n";
    $TGmessage .= "💸 佣金: <code>{$affmoney}元</code>\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📝 <b>主题</b>: {$e($ticket->subject)}\n";
    $TGmessage .= "💬 <b>内容</b>:\n<blockquote>{$e($content)}</blockquote>";

    if ($attachments->isNotEmpty()) {
      $names = $attachments->map(
        fn($a) => $e($a->original_name) . '（' . $this->formatBytes((int) $a->size) . '）'
      )->implode('、');
      $TGmessage .= "\n📎 <b>附件</b> {$attachments->count()} 个：{$names}";
      if (!$this->getConfig('notify_ticket_attachments', true)) {
        $TGmessage .= "\n（附件推送已在插件设置中关闭，请到后台查看）";
      }
    }

    $TGmessage .= "\n\n↩️ 直接回复本条消息即可回复工单，可附带图片或文件。";
    $this->telegramService->sendMessageWithAdmin($TGmessage, true, 'html');

    if ($attachments->isNotEmpty() && $this->getConfig('notify_ticket_attachments', true)) {
      foreach ($attachments as $index => $attachment) {
        // caption 里的「工单ID:」让管理员直接回复这张图片也能被识别为工单回复（见 registerReplyHandler 的正则）
        $caption = sprintf(
          "📎 工单 #%d 附件 %d/%d：%s（%s）\n工单ID: %d",
          $ticket->id,
          $index + 1,
          $attachments->count(),
          $attachment->original_name,
          $this->formatBytes((int) $attachment->size),
          $ticket->id
        );
        $this->telegramService->sendAttachmentWithAdmin($attachment, $caption, true);
      }
    }
  }

  protected function registerDefaultCommands(): void
  {
    foreach ($this->commandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }

    // 既匹配通知正文「📮 工单提醒 #12」/「📮 工单回复 #12」，也匹配附件 caption 里的「工单ID: 12」
    $this->registerReplyHandler('/(📮.*?工单(?:提醒|回复).*?#?|工单ID: ?)(\\d+)/u', [$this, 'handleTicketReply']);
  }

  public function registerTelegramCommand(string $command, callable $handler): void
  {
    $this->commands['commands'][$command] = $handler;
  }

  public function registerReplyHandler(string $regex, callable $handler): void
  {
    $this->commands['replies'][$regex] = $handler;
  }

  /**
   * 发送消息给用户（纯文本内容，走旧版 Markdown 自动转义）
   */
  protected function sendMessage(object $msg, string $message): void
  {
    $this->telegramService->sendMessage($msg->chat_id, $message, 'markdown');
  }

  /**
   * 检查是否为私聊
   */
  protected function checkPrivateChat(object $msg): bool
  {
    if (!$msg->is_private) {
      $this->sendMessage($msg, '请在私聊中使用此命令');
      return false;
    }
    return true;
  }

  /**
   * 获取绑定的用户
   */
  protected function getBoundUser(object $msg): ?User
  {
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user) {
      $this->sendMessage($msg, '请先绑定账号');
      return null;
    }
    return $user;
  }

  public function handleStartCommand(object $msg): void
  {
    $welcomeTitle = $this->getConfig('start_welcome_title', '🎉 欢迎使用 XBoard Telegram Bot！');
    $botDescription = $this->getConfig('start_bot_description', '🤖 我是您的专属助手，可以帮助您：\\n• 绑定您的 XBoard 账号\\n• 查看流量使用情况\\n• 获取最新订阅链接\\n• 管理账号绑定状态');
    $footer = $this->getConfig('start_footer', '💡 提示：所有命令都需要在私聊中使用');

    $welcomeText = $welcomeTitle . "\n\n" . $botDescription . "\n\n";

    $user = User::where('telegram_id', $msg->chat_id)->first();
    if ($user) {
      $welcomeText .= "✅ 您已绑定账号：{$user->email}\n\n";
      $welcomeText .= $this->getConfig('start_unbind_guide', '📋 可用命令：\\n/traffic - 查看流量使用情况\\n/getlatesturl - 获取订阅链接\\n/unbind - 解绑账号');
    } else {
      $welcomeText .= $this->getConfig('start_bind_guide', '🔗 请先绑定您的 XBoard 账号：\\n1. 登录您的 XBoard 账户\\n2. 复制您的订阅链接\\n3. 发送 /bind + 订阅链接') . "\n\n";
      $welcomeText .= $this->getConfig('start_bind_commands', '📋 可用命令：\\n/bind [订阅链接] - 绑定账号');
    }

    $welcomeText .= "\n\n" . $footer;
    $welcomeText = str_replace('\\n', "\n", $welcomeText);

    $this->sendMessage($msg, $welcomeText);
  }

  public function handleMessage(bool $handled, array $data): bool
  {
    list($msg) = $data;
    if ($handled)
      return $handled;

    try {
      return match ($msg->message_type) {
        'message' => $this->handleCommandMessage($msg),
        'reply_message' => $this->handleReplyMessage($msg),
        default => false
      };
    } catch (\Exception $e) {
      Log::error('Telegram 命令处理意外错误', [
        'command' => $msg->command ?? 'unknown',
        'chat_id' => $msg->chat_id ?? 'unknown',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
      ]);

      if (isset($msg->chat_id)) {
        $this->telegramService->sendMessage($msg->chat_id, '系统繁忙，请稍后重试');
      }

      return true;
    }
  }

  protected function handleCommandMessage(object $msg): bool
  {
    if (!isset($this->commands['commands'][$msg->command])) {
      return false;
    }

    call_user_func($this->commands['commands'][$msg->command], $msg);
    return true;
  }

  protected function handleReplyMessage(object $msg): bool
  {
    if (!isset($this->commands['replies'])) {
      return false;
    }

    foreach ($this->commands['replies'] as $regex => $handler) {
      if (preg_match($regex, $msg->reply_text, $matches)) {
        call_user_func($handler, $msg, $matches);
        return true;
      }
    }

    return false;
  }

  public function handleUnknownCommand(array $data): void
  {
    list($msg) = $data;
    if (!$msg->is_private || $msg->message_type !== 'message')
      return;
    // 单发一张图片 / 文件而没有回复任何通知：不是命令，也不值得回一段帮助文本
    if (!empty($msg->attachments) && trim((string) $msg->text) === '')
      return;

    $helpText = $this->getConfig('help_text', '未知命令，请查看帮助');
    $this->telegramService->sendMessage($msg->chat_id, $helpText);
  }

  public function handleError(array $data): void
  {
    list($msg, $e) = $data;
    Log::error('Telegram 消息处理错误', [
      'chat_id' => $msg->chat_id ?? 'unknown',
      'command' => $msg->command ?? 'unknown',
      'message_type' => $msg->message_type ?? 'unknown',
      'error' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]);
  }

  public function handleBindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $subscribeUrl = $msg->args[0] ?? null;
    if (!$subscribeUrl) {
      $this->sendMessage($msg, '参数有误，请携带订阅地址发送');
      return;
    }

    $token = $this->extractTokenFromUrl($subscribeUrl);
    if (!$token) {
      $this->sendMessage($msg, '订阅地址无效');
      return;
    }

    $user = User::where('token', $token)->first();
    if (!$user) {
      $this->sendMessage($msg, '用户不存在');
      return;
    }

    if ($user->telegram_id) {
      $this->sendMessage($msg, '该账号已经绑定了Telegram账号');
      return;
    }

    $user->telegram_id = $msg->chat_id;
    if (!$user->save()) {
      $this->sendMessage($msg, '设置失败');
      return;
    }

    HookManager::call('user.telegram.bind.after', [$user]);
    $this->sendMessage($msg, '绑定成功');
  }

  protected function extractTokenFromUrl(string $url): ?string
  {
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
      return null;
    }

    $parsedUrl = parse_url($url);
    if ($parsedUrl === false) {
      return null;
    }

    if (isset($parsedUrl['query'])) {
      parse_str($parsedUrl['query'], $query);
      if (isset($query['token']) && is_string($query['token']) && $this->isValidUserToken($query['token'])) {
        return strtolower($query['token']);
      }
    }

    if (isset($parsedUrl['path'])) {
      $pathParts = explode('/', trim($parsedUrl['path'], '/'));
      $lastPart = end($pathParts);
      if (is_string($lastPart) && $this->isValidUserToken($lastPart)) {
        return strtolower($lastPart);
      }
    }

    return null;
  }

  protected function isValidUserToken(string $token): bool
  {
    return (bool) preg_match('/^[a-f0-9]{32}$/i', $token);
  }

  public function handleTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $transferUsed = $user->u + $user->d;
    $transferTotal = $user->transfer_enable;
    $transferRemaining = $transferTotal - $transferUsed;
    $usagePercentage = $transferTotal > 0 ? ($transferUsed / $transferTotal) * 100 : 0;

    $text = sprintf(
      "📊 流量使用情况\n\n已用流量：%sG\n总流量：%sG\n剩余流量：%sG\n使用率：%.2f%%",
      $this->transferToGBString($transferUsed),
      $this->transferToGBString($transferTotal),
      $this->transferToGBString($transferRemaining),
      $usagePercentage
    );

    $this->sendMessage($msg, $text);
  }

  public function handleGetLatestUrlCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $subscribeUrl = Helper::getSubscribeUrl($user->token);
    $text = sprintf("🔗 您的订阅链接：\n\n%s", $subscribeUrl);

    $this->sendMessage($msg, $text);
  }

  public function handleUnbindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $user->telegram_id = null;
    if (!$user->save()) {
      $this->sendMessage($msg, '解绑失败');
      return;
    }

    $this->sendMessage($msg, '解绑成功');
  }

  /**
   * 管理员 / 客服在 Telegram 里回复工单通知 → 写入工单。可附带图片 / 文件（存为工单附件）。
   */
  public function handleTicketReply(object $msg, array $matches): void
  {
    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    // 之前只检查「绑定了账号」：任何用户回复一条含「工单ID: 5」的消息就能以管理员身份写进任意工单
    if (!$user->is_admin && !$user->is_staff) {
      $this->sendMessage($msg, '只有管理员或客服可以通过 Telegram 回复工单');
      return;
    }

    if (!isset($matches[2]) || !is_numeric($matches[2])) {
      Log::warning('Telegram 工单回复正则未匹配到工单ID', ['matches' => $matches, 'chat_id' => $msg->chat_id ?? null]);
      $this->sendMessage($msg, '未能识别工单ID，请直接回复工单提醒消息。');
      return;
    }

    $ticketId = (int) $matches[2];
    $ticket = Ticket::where('id', $ticketId)->first();
    if (!$ticket) {
      $this->sendMessage($msg, '工单不存在');
      return;
    }

    $attachmentIds = $this->storeIncomingAttachments($msg, $user);
    $text = trim((string) ($msg->text ?? ''));
    if ($text === '' && !$attachmentIds) {
      $this->sendMessage($msg, '回复内容为空，请输入文字或附上图片 / 文件');
      return;
    }

    $ticketService = new TicketService();
    $ticketService->replyByAdmin(
      $ticketId,
      $text,
      $user->id,
      $attachmentIds
    );

    $suffix = $attachmentIds ? '（含 ' . count($attachmentIds) . ' 个附件）' : '';
    $this->sendMessage($msg, "工单 #{$ticketId} 回复成功{$suffix}");
  }

  /**
   * 把管理员随回复发来的 Telegram 图片 / 文件下载下来存成待绑定的工单附件。
   *
   * @return int[] 附件 id；失败的逐个告知管理员并跳过
   */
  protected function storeIncomingAttachments(object $msg, User $uploader): array
  {
    $files = is_array($msg->attachments ?? null) ? $msg->attachments : [];
    if (!$files) {
      return [];
    }

    $service = new TicketAttachmentService();
    if (!$service->config()->enable) {
      $this->sendMessage($msg, '工单附件功能未开启，图片 / 文件未能附加到工单；请在后台「系统设置 → 工单附件」开启后重试。');
      return [];
    }

    $ids = [];
    foreach ($files as $file) {
      $name = $file['file_name'] ?? null;
      try {
        $info = $this->telegramService->getFile((string) $file['file_id']);
        $filePath = (string) ($info->result->file_path ?? '');
        if ($filePath === '') {
          throw new \RuntimeException('Telegram 未返回 file_path');
        }
        // Telegram 的照片没有原始文件名，用它存储路径的 basename（形如 file_12.jpg）
        $name = $name ?: basename($filePath);
        $contents = $this->telegramService->downloadFile($filePath);
        $attachment = $service->storeFromContents($name, $contents, $uploader, true);
        $ids[] = $attachment->id;
      } catch (\Throwable $e) {
        Log::warning('[telegram] 管理员回复附件处理失败', [
          'chat_id' => $msg->chat_id ?? null,
          'name' => $name,
          'error' => $e->getMessage(),
        ]);
        $this->sendMessage($msg, '附件 ' . ($name ?: '') . ' 未能附加：' . $e->getMessage());
      }
    }

    return $ids;
  }

  /**
   * 添加 Bot 命令到命令列表
   */
  public function addBotCommands(array $commands): array
  {
    foreach ($this->commandConfigs as $command => $config) {
      $commands[] = [
        'command' => $command,
        'description' => $config['description']
      ];
    }

    return $commands;
  }

  private function transferToGBString(float $transfer_enable, int $decimals = 2): string
  {
    return number_format(Helper::transferToGB($transfer_enable), $decimals, '.', '');
  }

  private function formatBytes(int $bytes): string
  {
    if ($bytes >= 1048576) {
      return number_format($bytes / 1048576, 1) . 'MB';
    }
    if ($bytes >= 1024) {
      return number_format($bytes / 1024, 0) . 'KB';
    }
    return $bytes . 'B';
  }
}
