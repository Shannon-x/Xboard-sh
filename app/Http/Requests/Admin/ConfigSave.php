<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ConfigSave extends FormRequest
{
    const RULES = [
        // invite & commission
        'invite_force' => '',
        'invite_commission' => 'integer|nullable|min:0|max:100',
        'invite_gen_limit' => 'integer|nullable',
        'invite_custom_code_enable' => '',
        'invite_never_expire' => '',
        'commission_first_time_enable' => '',
        'commission_auto_check_enable' => '',
        'commission_withdraw_limit' => 'nullable|numeric',
        'commission_withdraw_method' => 'nullable|array',
        'withdraw_close_enable' => '',
        'commission_distribution_enable' => '',
        'commission_distribution_l1' => 'nullable|numeric|min:0|max:100',
        'commission_distribution_l2' => 'nullable|numeric|min:0|max:100',
        'commission_distribution_l3' => 'nullable|numeric|min:0|max:100',
        // commission withdraw workflow
        'commission_withdraw_max' => 'nullable|numeric|min:0',
        'commission_withdraw_chains' => 'nullable|array|max:20',
        'commission_withdraw_chains.*.code' => 'nullable|string|max:32',
        'commission_withdraw_chains.*.name' => 'required_with:commission_withdraw_chains.*|string|max:64',
        'commission_withdraw_chains.*.network' => 'nullable|string|max:64',
        'commission_withdraw_chains.*.preset' => 'nullable|in:tron,evm,solana,ton,aptos,none',
        'commission_withdraw_chains.*.network_key' => 'nullable|string|max:32',
        'commission_withdraw_chains.*.explorer_tx' => 'nullable|string|max:255',
        'commission_withdraw_chains.*.fee' => 'nullable|numeric|min:0|max:10000',
        'commission_withdraw_rate_source' => 'nullable|in:auto,manual',
        'commission_withdraw_usdt_rate' => 'nullable|numeric|min:0|max:100000',
        'commission_withdraw_require_qrcode' => 'boolean',
        'commission_withdraw_thanks' => 'nullable|string|max:1000',
        'commission_withdraw_min_invitees' => 'nullable|integer|min:0|max:100000',
        'commission_transfer_require_invitees' => '',
        // invite growth
        'invite_first_order_discount' => 'nullable|integer|min:0|max:100',
        'commission_tier_enable' => '',
        'commission_tier_2_min' => 'nullable|integer|min:1|max:100000',
        'commission_tier_2_rate' => 'nullable|integer|min:0|max:100',
        'commission_tier_3_min' => 'nullable|integer|min:1|max:100000',
        'commission_tier_3_rate' => 'nullable|integer|min:0|max:100',
        'commission_period_auto_enable' => '',
        'commission_period_auto_min_invitees' => 'nullable|integer|min:1|max:100000',
        'commission_notify_enable' => '',
        'commission_notify_frequency' => 'nullable|in:instant,daily,weekly',
        'commission_leaderboard_enable' => '',
        // site
        'logo' => 'nullable|url',
        'force_https' => '',
        'stop_register' => '',
        'app_name' => '',
        'app_description' => '',
        'app_url' => 'nullable|url',
        'subscribe_url' => 'nullable',
        'try_out_enable' => '',
        'try_out_plan_id' => 'integer',
        'try_out_hour' => 'numeric',
        'tos_url' => 'nullable|url',
        'currency' => '',
        'currency_symbol' => '',
        'ticket_must_wait_reply' => '',
        // ticket attachment（硬上限见 AttachmentConfig::HARD_MAX_*，超过会被 Swoole / PHP 请求体上限掐断）
        'ticket_attachment_enable' => 'boolean',
        // 工单分类（code 列表见 TicketCategories::CATEGORIES；other 永远可选，写进来也不会被隐藏）
        'ticket_category_hidden' => 'nullable|string|max:255|regex:/^[a-z_]+(\s*[,，;\s]\s*[a-z_]+)*$/i',
        'ticket_feedback_enable' => 'boolean',
        'ticket_attachment_driver' => 'in:local,s3',
        'ticket_attachment_max_size_mb' => 'integer|min:1|max:20',
        'ticket_attachment_max_count' => 'integer|min:1|max:10',
        'ticket_attachment_allowed_extensions' => 'nullable|string|max:255|regex:/^[a-z0-9.]+(\s*[,，;\s]\s*[a-z0-9.]+)*$/i',
        'ticket_attachment_daily_quota_mb' => 'integer|min:0|max:10240',
        'ticket_attachment_retention_days' => 'integer|min:0|max:3650',
        'ticket_attachment_s3_endpoint' => 'nullable|url|max:255',
        'ticket_attachment_s3_region' => 'nullable|string|max:64',
        'ticket_attachment_s3_bucket' => 'nullable|string|max:128',
        'ticket_attachment_s3_access_key' => 'nullable|string|max:255',
        'ticket_attachment_s3_secret_key' => 'nullable|string|max:255',
        'ticket_attachment_s3_path_style' => 'boolean',
        'ticket_attachment_s3_prefix' => 'nullable|string|max:128|regex:/^[\w\-\/.]*$/',
        'ticket_attachment_s3_public_url' => 'nullable|url|max:255',
        // subscribe
        'plan_change_enable' => '',
        'reset_traffic_method' => 'in:0,1,2,3,4',
        'advance_cycle_used_ratio' => 'numeric|between:0.5,1',
        'surplus_enable' => '',
        // 增值节点组在用户端的区块标题；留空则前端回落 i18n 默认文案
        'addon_group_label' => 'nullable|string|max:32',
        // 续费助手：自动续费全站开关、提前 / 宽限小时数；仪表盘快捷续费提醒提前天数（0 = 不显示）
        'auto_renew_enable' => '',
        'auto_renew_lead_hours' => 'nullable|integer|min:1|max:168',
        'auto_renew_grace_hours' => 'nullable|integer|min:0|max:720',
        'renew_prompt_days' => 'nullable|integer|min:0|max:60',
        'new_order_event_id' => '',
        'renew_order_event_id' => '',
        'change_order_event_id' => '',
        'show_info_to_server_enable' => '',
        'show_protocol_to_server_enable' => '',
        'ticket_active_subscription_required' => '',
        'subscribe_path' => '',
        // server
        'server_token' => 'nullable|string|min:16|max:128',
        'server_pull_interval' => 'integer',
        'server_push_interval' => 'integer',
        'device_limit_mode' => 'integer',
        'server_ws_enable' => 'boolean',
        'server_ws_url' => 'nullable|url',
        // frontend
        'frontend_theme' => '',
        'frontend_theme_sidebar' => 'nullable|in:dark,light',
        'frontend_theme_header' => 'nullable|in:dark,light',
        'frontend_theme_color' => 'nullable|in:default,darkblue,black,green',
        'frontend_background_url' => 'nullable|url',
        // email
        'email_template' => 'nullable|string|max:64',
        'email_host' => 'nullable|string|max:255',
        'email_port' => 'nullable|string|max:16',
        'email_username' => 'nullable|string|max:255',
        'email_password' => 'nullable|string|max:255',
        'email_encryption' => 'nullable|string|max:32',
        'email_from_address' => 'nullable|string|email:strict|max:255',
        'remind_mail_enable' => '',
        // 流量提醒：预警阈值（百分比）与「流量已用完」开关，都按周期只发一封
        'remind_traffic_percent' => 'nullable|integer|min:50|max:99',
        'remind_traffic_exhausted_enable' => '',
        // 收据 / 续费账单邮件：开关、提前天数（0 或 1 = 只发到期前 24h 的最后提醒）、文案语言、开具方抬头、推荐套餐 id 列表
        'billing_receipt_enable' => '',
        'billing_invoice_enable' => '',
        'billing_invoice_days' => 'nullable|integer|min:0|max:30',
        // 账单邮件里的免登录付款链接：开关与到期后仍可付款的天数（0 = 到期即失效）
        'billing_pay_link_enable' => '',
        'billing_pay_link_days' => 'nullable|integer|min:0|max:30',
        'billing_locale' => 'nullable|in:zh-CN,zh-TW,en-US',
        'billing_issuer' => 'nullable|string|max:255',
        'billing_logo' => ['nullable', 'string', 'max:500', 'regex:#^(https?://\S+|/\S*)$#'],
        'billing_recommend_plan_ids' => ['nullable', 'string', 'max:255', 'regex:/^\s*(\d+\s*(,\s*\d+\s*)*)?$/'],
        'billing_expired_enable' => '',
        'billing_winback_enable' => '',
        'billing_winback_days' => ['nullable', 'string', 'max:32', 'regex:/^\s*(\d+\s*(,\s*\d+\s*)*)?$/'],
        'billing_winback_coupon' => 'nullable|string|max:64',
        'mail_digest_enable' => '',
        // 已续费 / 已失效的账单记录保留天数与邮件投递日志保留天数（0 = 永久）
        'billing_invoice_retention_days' => 'nullable|integer|min:0|max:3650',
        'mail_log_retention_days' => 'nullable|integer|min:0|max:3650',
        'notify_optional_categories' => 'nullable|string|max:191|regex:/^[a-z_,]*$/',
        'notify_footer_label' => 'nullable|string|max:64',
        'notify_list_unsubscribe_enable' => '',
        // 邮箱软验证：开关、宽限天数、限制方式、到期前提醒天数、注册时查 MX
        'email_verify_nudge_enable' => '',
        'email_verify_grace_days' => 'nullable|integer|min:1|max:90',
        'email_verify_restrict_mode' => 'nullable|in:features,subscribe,none',
        'email_verify_remind_days' => 'nullable|integer|min:0|max:30',
        'email_verify_mx_check' => '',
        // telegram
        'telegram_bot_enable' => 'boolean',
        'telegram_bot_token' => 'nullable|string|max:128',
        // 用于校验 Telegram POST 到 /api/v1/guest/telegram/webhook 的 access_token。
        // 必须是 hex 串（与历史 md5(bot_token) 等位宽以保持前向兼容），未配置时回退 md5(bot_token)。
        'telegram_webhook_secret' => 'nullable|string|min:32|max:64|regex:/^[a-f0-9]+$/i',
        'telegram_webhook_url' => 'nullable|url',
        'telegram_discuss_id' => 'nullable|string|max:64',
        'telegram_channel_id' => 'nullable|string|max:64',
        'telegram_discuss_link' => 'nullable|url',
        // app
        'windows_version' => '',
        'windows_download_url' => '',
        'macos_version' => '',
        'macos_download_url' => '',
        'android_version' => '',
        'android_download_url' => '',
        // safe
        'email_whitelist_enable' => 'boolean',
        'email_whitelist_suffix' => 'nullable|array',
        'email_gmail_limit_enable' => 'boolean',
        'captcha_enable' => 'boolean',
        'captcha_type' => 'in:recaptcha,turnstile,recaptcha-v3',
        'recaptcha_enable' => 'boolean',
        'recaptcha_key' => 'nullable|string|max:255',
        'recaptcha_site_key' => 'nullable|string|max:255',
        'recaptcha_v3_secret_key' => 'nullable|string|max:255',
        'recaptcha_v3_site_key' => 'nullable|string|max:255',
        'recaptcha_v3_score_threshold' => 'numeric|min:0|max:1',
        'turnstile_secret_key' => 'nullable|string|max:255',
        'turnstile_site_key' => 'nullable|string|max:255',
        'google_login_enable' => 'boolean',
        'google_client_id' => 'nullable|string|max:255',
        'google_client_secret' => 'nullable|string|max:255',
        'google_redirect_uri' => 'nullable|url|max:255',
        'email_verify' => 'bool',
        'safe_mode_enable' => 'boolean',
        'register_limit_by_ip_enable' => 'boolean',
        'register_limit_count' => 'integer',
        'register_limit_expire' => 'integer',
        'secure_path' => 'min:8|regex:/^[\w-]*$/',
        'password_limit_enable' => 'boolean',
        'password_limit_count' => 'integer',
        'password_limit_expire' => 'integer',
        // 后台会话有效期（天）：两步验证通过后签发的 token 到期就得重新登录再验一次
        'admin_session_days' => 'integer|min:1|max:90',
        'default_remind_expire' => 'boolean',
        'default_remind_traffic' => 'boolean',
        // balance recharge（金额单位元；默认值与归一化见 App\Services\Recharge\RechargeConfig）
        'recharge_enable' => '',
        'recharge_min_amount' => 'nullable|numeric|decimal:0,2|min:0.01|max:1000000',
        'recharge_max_amount' => 'nullable|numeric|decimal:0,2|min:0.01|max:1000000',
        'recharge_bonus_tiers' => 'nullable|array|max:20',
        'recharge_bonus_tiers.*.amount' => 'required|numeric|decimal:0,2|min:0.01|max:1000000',
        'recharge_bonus_tiers.*.bonus' => 'required|numeric|decimal:0,2|min:0.01|max:1000000',
        'recharge_quick_amounts' => 'nullable|array|max:12',
        'recharge_quick_amounts.*' => 'numeric|decimal:0,2|min:0.01|max:1000000',
        // 上网按量（金额单位元；默认值与归一化见 App\Services\Payg\PaygConfig）
        'payg_plan_ids' => 'nullable|array|max:20',
        'payg_plan_ids.*' => 'integer|min:1',
        'payg_monthly_floor' => 'nullable|numeric|decimal:0,2|min:0|max:1000',
        'payg_first_bonus_gb' => 'nullable|integer|min:0|max:10000',
        'payg_bonus_tiers' => 'nullable|array|max:10',
        'payg_bonus_tiers.*.amount' => 'required|numeric|decimal:0,2|min:0.01|max:1000000',
        'payg_bonus_tiers.*.bonus' => 'required|numeric|decimal:0,2|min:0.01|max:1000000',
        'payg_auto_topup_enable' => '',
        'payg_auto_topup_threshold' => 'nullable|numeric|decimal:0,2|min:0|max:1000',
        'subscribe_template_singbox' => 'nullable',
        'subscribe_template_clash' => 'nullable',
        'subscribe_template_clashmeta' => 'nullable',
        'subscribe_template_stash' => 'nullable',
        'subscribe_template_surge' => 'nullable',
        'subscribe_template_surfboard' => 'nullable'
    ];
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return self::RULES;
    }

    /**
     * 入参归一化：把「规则要求字符串、但前端常以 JSON 数字下发」的字段统一转成字符串，
     * 避免 validation.string 误拒。
     *
     * 典型：XBoard-admin 的邮件设置把端口写成 email_port: Number(emailPort)，
     * 587（JSON number）撞 `nullable|string|max:16` 直接保存失败。后端归一化比要求各前端
     * 都改成字符串更前向兼容（多前端共存）。
     */
    protected function prepareForValidation(): void
    {
        $stringifyIfScalar = ['email_port'];
        $patch = [];
        foreach ($stringifyIfScalar as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                if (is_int($value) || is_float($value)) {
                    $patch[$key] = (string) $value;
                }
            }
        }
        if ($patch) {
            $this->merge($patch);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateRecharge($validator);
            $this->validatePayg($validator);
            if (!(int) $this->input('commission_distribution_enable', 0)) {
                return;
            }

            $total = collect([
                $this->input('commission_distribution_l1', 0),
                $this->input('commission_distribution_l2', 0),
                $this->input('commission_distribution_l3', 0),
            ])->sum(fn($value) => (float) ($value ?? 0));

            if ($total > 100) {
                $validator->errors()->add('commission_distribution_l1', '三级分销比例合计不能超过 100%');
            }
        });
    }

    /** 余额充值：最高不能低于最低；单档赠送不能超过该档门槛（防止把 100:10 手误成 10:100 白送钱） */
    private function validateRecharge(Validator $validator): void
    {
        $min = $this->input('recharge_min_amount');
        $max = $this->input('recharge_max_amount');
        if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
            $validator->errors()->add('recharge_max_amount', '单笔最高充值金额不能低于最低金额');
        }
        foreach ((array) $this->input('recharge_bonus_tiers', []) as $i => $tier) {
            if (is_array($tier) && is_numeric($tier['amount'] ?? null) && is_numeric($tier['bonus'] ?? null)
                && (float) $tier['bonus'] > (float) $tier['amount']) {
                $validator->errors()->add("recharge_bonus_tiers.{$i}.bonus", '赠送金额不能超过该档的充值门槛');
            }
        }
    }

    /**
     * 上网按量：认定的套餐必须能换算单价（一次性价 + 流量），而且流量不重置 ——
     * 按量余额就是剩余流量，跟随系统按月重置会把已用清零 = 每月白送一次。赠送同样不能超过门槛。
     */
    private function validatePayg(Validator $validator): void
    {
        $ids = $this->input('payg_plan_ids');
        if (is_array($ids) && $ids !== []) {
            $plans = \App\Models\Plan::whereIn('id', array_filter($ids, 'is_numeric'))->get()->keyBy('id');
            foreach ($ids as $i => $id) {
                $plan = is_numeric($id) ? $plans->get((int) $id) : null;
                if (!$plan) {
                    $validator->errors()->add("payg_plan_ids.{$i}", "套餐 #{$id} 不存在");
                } elseif (\App\Services\Payg\PaygConfig::unitPrice($plan) === null) {
                    $validator->errors()->add("payg_plan_ids.{$i}", "「{$plan->name}」没有一次性价格或流量，算不出每 GB 单价");
                } elseif ($plan->reset_traffic_method !== \App\Models\Plan::RESET_TRAFFIC_NEVER) {
                    $validator->errors()->add("payg_plan_ids.{$i}", "「{$plan->name}」的流量重置方式必须是「不重置」：按量余额就是剩余流量");
                }
            }
        }
        foreach ((array) $this->input('payg_bonus_tiers', []) as $i => $tier) {
            if (is_array($tier) && is_numeric($tier['amount'] ?? null) && is_numeric($tier['bonus'] ?? null)
                && (float) $tier['bonus'] > (float) $tier['amount']) {
                $validator->errors()->add("payg_bonus_tiers.{$i}.bonus", '赠送金额不能超过该档的充值门槛');
            }
        }
    }

    public function messages()
    {
        // illiteracy prompt
        return [
            'app_url.url' => '站点URL格式不正确，必须携带http(s)://',
            'subscribe_url.url' => '订阅URL格式不正确，必须携带http(s)://',
            'server_token.min' => '通讯密钥长度必须大于16位',
            'tos_url.url' => '服务条款URL格式不正确，必须携带http(s)://',
            'telegram_webhook_url.url' => 'Telegram Webhook地址格式不正确，必须携带http(s)://',
            'telegram_discuss_link.url' => 'Telegram群组地址必须为URL格式，必须携带http(s)://',
            'logo.url' => 'LOGO URL格式不正确，必须携带https(s)://',
            'secure_path.min' => '后台路径长度最小为8位',
            'secure_path.regex' => '后台路径只能为字母或数字',
            'captcha_type.in' => '人机验证类型只能选择 recaptcha、turnstile 或 recaptcha-v3',
            'recaptcha_v3_score_threshold.numeric' => 'reCAPTCHA v3 分数阈值必须为数字',
            'recaptcha_v3_score_threshold.min' => 'reCAPTCHA v3 分数阈值不能小于0',
            'recaptcha_v3_score_threshold.max' => 'reCAPTCHA v3 分数阈值不能大于1',
            'google_redirect_uri.url' => 'Google OAuth 回调地址格式不正确，必须携带 http(s)://',
            'ticket_attachment_max_size_mb.max' => '单个附件上限最大 20 MB（受 Swoole / PHP 请求体上限约束）',
            'ticket_attachment_max_count.max' => '每条消息附件数量最多 10 个',
            'ticket_attachment_allowed_extensions.regex' => '允许的扩展名只能是字母数字，用逗号分隔，例如 jpg,png,pdf',
            'ticket_attachment_s3_endpoint.url' => 'S3 Endpoint 格式不正确，必须携带 http(s)://',
            'ticket_attachment_s3_public_url.url' => 'S3 公开访问地址格式不正确，必须携带 http(s)://',
            'ticket_attachment_s3_prefix.regex' => 'S3 存储前缀只能包含字母数字、-_./',
            'commission_withdraw_chains.*.name.required_with' => '提现链的名称不能为空',
            'commission_withdraw_chains.*.preset.in' => '地址格式预设只能是 tron / evm / solana / ton / aptos / none',
            'commission_withdraw_chains.*.fee.numeric' => '通道费必须是数字（以 USDT 计）',
            'commission_withdraw_rate_source.in' => '汇率来源只能是 auto（自动获取）或 manual（手动固定）',
            'commission_withdraw_chains.max' => '提现链最多配置 20 条',
            'recharge_min_amount.*' => '最低充值金额须为 0.01–1000000 元，最多两位小数',
            'recharge_max_amount.*' => '最高充值金额须为 0.01–1000000 元，最多两位小数',
            'recharge_bonus_tiers.max' => '赠送档位最多 20 档',
            'recharge_bonus_tiers.*.amount.*' => '赠送档位的充值门槛须为 0.01–1000000 元，最多两位小数',
            'recharge_bonus_tiers.*.bonus.*' => '赠送金额须为 0.01–1000000 元，最多两位小数',
            'recharge_quick_amounts.max' => '快捷金额最多 12 个',
            'recharge_quick_amounts.*.*' => '快捷金额须为 0.01–1000000 元，最多两位小数',
            'payg_plan_ids.max' => '按量套餐最多 20 个',
            'payg_plan_ids.*.*' => '按量套餐须填套餐 ID',
            'payg_monthly_floor.*' => '月度保底须为 0–1000 元，最多两位小数（0 = 不设保底）',
            'payg_first_bonus_gb.*' => '首充赠送须为 0–10000 GB 的整数',
            'payg_bonus_tiers.max' => '按量充值赠送最多 10 档',
            'payg_bonus_tiers.*.amount.*' => '按量赠送档位的充值门槛须为 0.01–1000000 元，最多两位小数',
            'payg_bonus_tiers.*.bonus.*' => '按量赠送金额须为 0.01–1000000 元，最多两位小数',
            'payg_auto_topup_threshold.*' => '自动补充门槛须为 0–1000 元，最多两位小数',
        ];
    }
}
