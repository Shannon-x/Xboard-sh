<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use App\Services\AuthService;
use Illuminate\Console\Command;

/**
 * 管理员丢了手机又没有恢复码时的最后手段：解除两步验证绑定并注销其全部会话，
 * 下次登录重新扫码绑定。只能在服务器上执行，不提供任何接口。
 */
class AdminTwoFactorReset extends Command
{
    protected $signature = 'admin:two-factor-reset {email : 管理员邮箱}';
    protected $description = '解除某个管理员的两步验证绑定并注销其所有会话（下次登录重新绑定）';

    public function handle(AdminTwoFactorService $twoFactor): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::byEmail($email)->first();
        if (!$user) {
            $this->error("找不到用户：{$email}");
            return self::FAILURE;
        }
        if (!$user->is_admin) {
            $this->error("{$email} 不是管理员");
            return self::FAILURE;
        }
        if (!$this->confirm("将解除 {$email} 的两步验证并注销其全部登录会话，继续？", true)) {
            return self::SUCCESS;
        }
        $twoFactor->reset($user);
        (new AuthService($user))->removeAllSessions();
        $this->info("已解除 {$email} 的两步验证绑定并注销全部会话；下次登录后台会重新引导绑定验证器。");
        return self::SUCCESS;
    }
}
