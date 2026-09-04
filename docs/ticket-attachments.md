# 工单附件（图片 / 文件上传）

用户与管理员在工单里互发截图与文件。支持剪贴板直接粘贴、拖拽、文件选择；存储可选本地或 S3 兼容对象存储；带体积 / 数量 / 每日额度限制与按保留期自动清理。

## 配置项（后台 → 系统设置 → 工单附件）

| 键 | 默认 | 说明 |
|---|---|---|
| `ticket_attachment_enable` | `0` | 总开关。默认关闭，升级不会悄悄打开上传入口 |
| `ticket_attachment_driver` | `local` | `local`（storage/app/ticket-attachments）或 `s3` |
| `ticket_attachment_max_size_mb` | `5` | 单文件上限，硬上限 20（见下「体积上限」） |
| `ticket_attachment_max_count` | `5` | 每条消息附件数，硬上限 10；同时也是单用户「已上传未发送」的囤积上限 |
| `ticket_attachment_allowed_extensions` | `jpg,jpeg,png,gif,webp,pdf,txt,log,zip` | 允许的扩展名。图片按内容识别（粘贴的截图文件名不可靠），非图片扩展名与内容 MIME 必须相容 |
| `ticket_attachment_daily_quota_mb` | `30` | 单用户 24h 上传总量，0 = 不限。管理员不受此限 |
| `ticket_attachment_retention_days` | `365` | 保留天数，到期由清理任务删除文件与记录；0 = 永久保留。可选 180 / 365 / 730 / 1095 |
| `ticket_attachment_s3_endpoint` | 空 | 空 = `https://s3.{region}.amazonaws.com`；R2 填 `https://<account>.r2.cloudflarestorage.com`，MinIO 填自建地址 |
| `ticket_attachment_s3_region` | `auto` | R2 用 `auto`，AWS 填真实区域 |
| `ticket_attachment_s3_bucket` / `_access_key` / `_secret_key` | 空 | 凭证 |
| `ticket_attachment_s3_path_style` | `1` | 路径式 `{endpoint}/{bucket}/{key}`；关闭则为 `{bucket}.{host}` 虚拟主机式 |
| `ticket_attachment_s3_prefix` | `ticket-attachments` | 对象 key 前缀 |
| `ticket_attachment_s3_public_url` | 空 | 桶公开读 / 挂了 CDN 时填，下载直接 302 到 `{public_url}/{key}`；留空则 302 到 10 分钟有效的预签名 URL，桶无需公开 |

后台的「测试存储连接」按钮会用表单里的当前值（无需先保存）写入 → 读回 → 删除一个探针对象。

## 接口

用户端（`user` 中间件）：

- `POST /api/v1/user/ticket/attachment/upload` —— multipart 字段 `file`，或 JSON `{ name, content(base64, 可带 data: 前缀) }`。返回 `{ id, name, size, mime, is_image, width, height, path, url }`。此时附件处于「待绑定」状态。
- `POST /api/v1/user/ticket/attachment/delete` —— `{ id }`，只能撤回待绑定的附件。
- `POST /api/v1/user/ticket/save` / `reply` —— 新增可选 `attachment_ids: number[]`。`reply` 在带附件时允许 `message` 为空。绑定在创建消息的同一事务里完成，并校验归属 / 未绑定 / 数量。
- `GET /api/v1/user/comm/config` —— 新增 `ticket_attachment: { enable, max_size_mb, max_count, allowed_extensions, daily_quota_mb }`。
- 工单详情 `message[].attachments[]`。

免登录：

- `GET /api/v1/guest/ticket/attachment/{id}/{access_key}` —— `<img>` 带不上 Bearer，用 URL 里 128 位随机 key 作能力凭据。只有已随消息发出的附件可下载。本地驱动流式输出，S3 驱动 302 到预签名 / 公开地址。图片（jpeg/png/gif/webp）内联，其它类型一律 `attachment` + `nosniff`。

后台（`admin` 中间件）：

- `POST .../ticket/attachment/upload`（不受用户额度限制）、`POST .../ticket/attachment/delete`（可删任意附件）、`ticket/reply` 同样接受 `attachment_ids`；`ticket/fetch?id=` 的 `messages[].attachments[]` 带 `download_url` / `download_path`。
- `POST .../config/testTicketAttachmentStorage`。

## 体积上限

三处必须一致：

1. `AttachmentConfig::HARD_MAX_SIZE_MB = 20`（后台配置的上限）；
2. `config/octane.php` → `swoole.options.package_max_length = 32MB`（Swoole 单请求体硬上限，Octane 默认 10MB，超过直接断连）；
3. `.docker/usr/local/etc/php/conf.d/zz-xboard-uploads.ini` → `post_max_size = upload_max_filesize = 32M`（Laravel `ValidatePostSize` 按 `post_max_size` 返回 413）。

base64 JSON 形态会膨胀 1/3，所以 20MB 文件约 27MB 请求体，留在 32MB 之内。自行部署（非 Docker）的请对照修改 php.ini，前置 nginx / Caddy 的 `client_max_body_size` 也要放到 32M 以上。

## 清理

`php artisan ticket:clean-attachments [--dry-run]`，计划任务每天 03:20 执行：

- 上传超过 24h 仍未随消息发出的；
- 所属工单已被删除的（后台删用户会连带删工单，删用户时也会同步删除该用户的附件文件）；
- 超过保留期的（`ticket_attachment_retention_days > 0` 时）。

文件删除失败（如 S3 凭证已失效）会保留记录并写 warning 日志，下一轮重试。

## S3 实现说明

未引入 `aws/aws-sdk-php`（Docker 构建严格按 `composer.lock` 安装，SDK 需重解析 lock），而是用 Guzzle + 自实现的 SigV4（`App\Services\TicketAttachment\Storage\S3SignatureV4`），并以 AWS 官方文档示例向量做了单元测试。已知与 R2 / MinIO / B2 / OSS S3 网关兼容。

## 与 stealth 中间件配合

`sufe-middleware-rs` 的混淆路径表是精确匹配，`/api/v1/user/ticket/attachment/upload` 与 `.../delete` 已登记进静态表；下载路径带动态段，走中间件新增的「混淆前缀直通」：`/v2/<hmac(/api/v1/guest/ticket/attachment)>/<id>/<key>` 由中间件流式转发二进制响应（含 302），不暴露 `/api/v1` 关键字。前端主题在 `NEXT_PUBLIC_SECURE_API=1` 时自动改写成该形态。
