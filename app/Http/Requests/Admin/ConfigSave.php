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
        // subscribe
        'plan_change_enable' => '',
        'reset_traffic_method' => 'in:0,1,2,3,4',
        'advance_cycle_used_ratio' => 'numeric|between:0.5,1',
        'surplus_enable' => '',
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
        'default_remind_expire' => 'boolean',
        'default_remind_traffic' => 'boolean',
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
            'google_redirect_uri.url' => 'Google OAuth 回调地址格式不正确，必须携带 http(s)://'
        ];
    }
}
