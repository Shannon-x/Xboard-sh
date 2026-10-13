# 后台登录两步验证与会话加固

管理员登录后台必须通过验证器（TOTP，RFC 6238）二次验证；没绑定的管理员在下次登录时被强制绑定。配套：后台令牌与用户面板令牌分级、后台登录有效期可调、会话列表与注销、登录审计与 Telegram 告警。用户面板、下单、续费、支付链路不受影响。

## 登录流程

1. 管理面板 POST `/api/v2/<secure_path>/auth/login` `{email, password}`。密码正确且是管理员时返回 `{two_factor, challenge, expires_in}`：`two_factor` 为 `required`（已绑定，进验证码步骤）或 `setup_required`（未绑定，进绑定步骤）。`challenge` 是一次性的登录挑战，5 分钟过期，验证码连续错 5 次作废，作废后要重新输密码。
2. 未绑定：POST `/auth/two-factor/setup` `{challenge}` 拿到 `secret` / `secret_grouped` / `otpauth_url` / `qr_svg`（data URI），扫码后 POST `/auth/two-factor/confirm` `{challenge, otp}`，成功返回 8 个一次性恢复码和后台令牌。
3. 已绑定：POST `/auth/two-factor/verify` `{challenge, otp}`，`otp` 填 6 位验证码或一个恢复码，成功返回后台令牌、`used_recovery_code`、`recovery_codes_remaining`。

验证码按 ±1 个时间步（30 秒）容错；同一个时间步的验证码只能用一次（`last_used_step`），拿到一次性验证码也无法重放。恢复码用 bcrypt 散列保存，用过即删，页面上会提示尽快重新生成。

### 防爆破与防探测

- 密码错误沿用原有锁定（`password_limit_enable` / `password_limit_count` 默认 5 次 / `password_limit_expire` 默认 60 分钟）；第一次触发锁定时推送 Telegram。
- 限流：`/auth/login` 每邮箱 5 次/分钟、每 IP 20 次/分钟（`throttle:admin-login`）；三个 two-factor 接口每 IP 20 次/分钟（`throttle:admin-two-factor`）。
- 邮箱不存在、不是管理员、密码错误都返回同一句 `Incorrect email or password`，对不存在的邮箱也做一次 bcrypt 比较，响应时间一致。
- 密码正确但验证码连续错 5 次：挑战作废、写审计 `auth.two_factor_locked`，并推送 Telegram（「密码正确但两步验证连续错误」，提示立即改密码）。

公开接口 `/api/v1/passport/auth/login` 对管理员账号照常可用（用户面板登录），但只发用户级令牌（见下）。

## 令牌分级

| 来源 | 能力 | 有效期 |
|---|---|---|
| 用户面板登录（`/api/v1/passport/auth/login`、Google、邮件链接等） | `['user']` | 1 年（原样） |
| 后台登录（两步验证通过后） | `['user', 'admin']` | 后台设置 `admin_session_days`，默认 7 天，1–90 |

`Admin` 中间件除了 `is_admin` 之外还检查当前令牌带 `admin` 能力；旧令牌（能力 `*`）与用户面板令牌访问 `/api/v2/<secure_path>/*` 一律 `403 {"code": "ADMIN_2FA_REQUIRED"}`。所以上线后所有已登录的后台会话自动失效，每个管理员下次登录各自绑定一次。

## 账号安全页接口（需要后台令牌）

| 路径 | 方法 | 用途 |
|---|---|---|
| `/api/v2/<secure_path>/security/two-factor` | GET | 状态：是否启用、绑定时间、剩余恢复码、有效期天数、当前会话 |
| `/security/two-factor/setup` | POST `{otp}` | 重新绑定验证器第一步（先验旧验证器），返回新二维码 |
| `/security/two-factor/confirm` | POST `{otp}` | 用新验证器的验证码完成换绑，返回新恢复码 |
| `/security/two-factor/recovery-codes` | POST `{otp}` | 重新生成恢复码（旧的立即作废） |
| `/security/sessions` | GET | 本账号的后台会话：IP、客户端、登录 / 最近活动 / 到期时间，标出当前会话 |
| `/security/sessions/revoke` | POST `{id}` | 注销一个会话 |
| `/security/sessions/revoke-others` | POST | 注销除当前外的全部会话 |
| `/security/login-log` | GET `?limit` | 所有管理员的 `auth.*` 审计记录 |

## 存储与审计

- `v2_admin_two_factor`：`user_id`（主键）、`secret` / `pending_secret`（用 `APP_KEY` 加密，`encrypted` cast —— 换 `APP_KEY` 会让所有绑定失效）、`confirmed_at`、`recovery_codes`（bcrypt 散列数组）、`last_used_step`。
- 审计写 `v2_admin_audit_log`，动作：`auth.login`、`auth.login_failed`、`auth.two_factor_failed`、`auth.two_factor_locked`、`auth.two_factor_enrolled`、`auth.recovery_code_used`、`auth.recovery_codes_regenerated`、`auth.session_revoked`、`auth.sessions_revoked`。后台「系统日志 → 登录」筛选按 `auth.*` 匹配。
- `RequestLog` 脱敏新增 `otp`、`recovery_code`、`challenge`、`totp`（字段名刻意不用 `code`，优惠码 / 礼品卡的 `code` 要留在日志里）。
- 每次后台登录成功、密码锁定、验证码锁定都调 `TelegramService::sendMessageWithAdmin`（需要 `telegram_bot_enable` 且管理员已绑定机器人）。

## 运维

- 手机丢失且没有恢复码：`php artisan admin:two-factor-reset <email>`，删除绑定并注销该管理员所有会话，下次登录重新绑定。刻意不提供任何网页入口。
- 上线：跑迁移（新表 `v2_admin_two_factor`）即可，无需改中间件 / 前端主题，后台路径本来就不走混淆路径表。
- 本地 / 测试：`phpunit.xml` 固定了 `APP_KEY`（`encrypted` cast 需要），`tests/Feature/AdminTwoFactorTest.php` 覆盖 RFC 6238 测试向量、首次绑定、重放、恢复码、锁定、令牌分级、换绑、会话注销与 CLI 重置。测试里的管理员要用 `Sanctum::actingAs($admin, ['admin'])`。
