# 知识库公开发布与权限

本版本增加文章级可见范围；分类和 `show` 仍独立存在。真实文章不会因升级自动公开。

## 配置与发布顺序

1. 备份数据库，先部署包含本版本的后端和依赖，执行迁移。
2. 执行 `php artisan xboard:check-upgrade`，确认没有待执行迁移；所有 API 实例都必须使用新权限读取逻辑。
3. 部署支持 `knowledge/capabilities` 的管理端，再部署新主题。能力探测未通过时禁止使用权限发布表单。
4. 后台逐篇审核文章。确认没有个人凭证、内部信息或受保护图片后，设置为公开并发布。
5. 最后显式设置 `KNOWLEDGE_PUBLIC_ENABLED=true`；若使用配置缓存，应重建配置并重启长驻进程。

默认 `KNOWLEDGE_PUBLIC_ENABLED=false`，访客接口返回 HTTP 503，`error.code=knowledge_public_disabled`；这不是“没有文章”。私有、未发布、不存在或语言不匹配的详情统一 404。

相对图片和下载链接需要 `KNOWLEDGE_MEDIA_BASE_URL=https://你的后端公开域名`，只接受 http(s) origin，不带路径、账号、查询或片段。解析从不信任请求 Host；未配置或配置非法时移除相对资源 URL，避免落到独立主题域名。目录链接 `#heading` 保留。后台直接填写绝对 HTTPS URL 则不依赖此配置。

## 权限和旧版本兼容

- `public`：公开指南。
- `members`：登录用户文章，旧数据迁移后的默认值。
- `subscribers`：满足既有 `UserService::isAvailable()` 的订阅用户文章。
- `admin`：仅管理接口可读，管理员本人通过 user 接口也不会看到内部文章。

未知范围一律不可见。会员旧文章的 access 块及个人订阅链接替换保留，搜索只匹配已经按权限处理的正文。

所有旧文保留原 `show`、正文和分类。旧管理端没传 `visibility` 时不改变已有权限。新建默认 `members` 且不显示；公开草稿也必须填写有效 slug 和摘要。

**不能只换回旧后端镜像。** 旧版本只看 `show`，会把内部及订阅专属文章暴露给普通登录用户。

- 回滚前必须执行 `php artisan xboard:check-upgrade --require-legacy-rollback`；存在新范围时命令失败。
- 迁移 `down()` 拒绝删除仍被使用的权限和发布元数据。
- 这些门禁不会替运维拦截手动启动的旧二进制。禁止混跑旧实例；只回滚主题或管理界面时继续保留新后端的权限执行。
- 必须降级时先停止知识库读写和公开入口，在离线备份上制定逐篇审核的降级方案。不得删除权限字段或自动扩大范围来换取启动成功。

## 管理 API

路径沿用 `/api/v2/{secure_path}/knowledge/*`，仍要求管理员鉴权：

- `GET capabilities`：`data.publication_version=1`、四项 `visibilities`、`public_enabled`。
- `POST save`：原字段加 `visibility / slug / summary / public_reviewed`；成功 `data=true`。
- slug 最长 120，只含小写字母、数字和分隔单横线，按语言唯一；summary 最长 500。
- 公开语言使用 `zh-CN / zh-TW / en / ja / ko / de`；不自动重写旧会员文章语言。
- 最终为已发布公开文章的保存都要求 `public_reviewed=true`，包括旧后台对公开文章的修改。
- 唯一允许的公共模板是精确 `{{siteName}}`；未知模板、个人订阅占位符、access 标记和可识别订阅凭证均拒绝公开，正文与元数据都检查。
- `POST show` 可带期望 `show` 布尔值，重复撤回不会重新发布。不带则兼容旧 toggle。公开文章从隐藏变发布只能走编辑审核，不能快捷切换。
- 普通校验失败返回 422 和 `errors`；`published_at` 由后端记录首次公开时间，客户端不能任意设置。

模板检测不是全自动保密审核。直接粘贴的未知格式凭证、图片内容或附件仍必须人工确认；敏感附件不能使用公开图床。

## 访客 API

`GET /api/v1/guest/knowledge/fetch?language=zh-CN&category=…&keyword=…&page=1&page_size=12`

`data` 包含：

- `items`：`slug / language / category / title / summary / published_at / updated_at`。
- `categories`：当前语言所有安全公开文章的 `{name,count}` 列表，不包含私人分类。
- `pagination`：数值型 `current_page / per_page / total / last_page`，page_size 最大 100。

`GET /api/v1/guest/knowledge/article/{slug}?language=zh-CN`：相同元数据，加 `body_format="html"` 和安全 HTML `body`。

正文经过 CommonMark 解析，再由 Symfony HtmlSanitizer 明确白名单清洗。不能再次当 Markdown 解析，也不能跳过前端自身的安全渲染约束。接口不执行个人模板替换或用户资源插件钩子。

列表、分类、数量、搜索和详情共享最后的内容守卫。列表以 50 条为批次读取公开候选，只保留当前页元数据，不缓存所有正文；超大知识库后续可增加独立审核索引，但不能放宽访问范围换取性能。

所有知识库 API 返回 `Cache-Control: private, no-store` 与 CDN no-store；主题服务端也应 `cache: no-store`，正文禁止进入共享个性化缓存。文章撤回后新请求即重新判断数据库权限。部署前如存在额外 CDN “缓存所有内容”规则，必须移除/旁路并清除历史缓存；外部转载不能保证撤回。

## 本地示例，不连接真实数据库

`scripts/seed-knowledge-preview.php` 只允许 `APP_ENV=local`、`KNOWLEDGE_PREVIEW_FIXTURE=1`、`DB_CONNECTION=sqlite`、`DB_DATABASE=database/knowledge-preview.sqlite`，并拒绝存在 `.env` 或缓存配置的目录。

应在隔离工作树提供进程级 APP_KEY、APP_URL、数组缓存和同步队列，然后运行此脚本。它只在空的专用演示数据库中创建三个标题标注“演示”的公开指南及三个私有权限样例。已有文章时不覆盖。SQLite 文件和 WAL/SHM 均不进入 Git 或 Docker 构建上下文。

可用相同隔离环境执行 `php artisan serve --host=127.0.0.1 --port=8008` 进行前端真实 HTTP 联调。该演示不是生产内容迁移，也不能替代以下权限与目标数据库测试。

## 验证

`vendor/bin/phpunit --filter KnowledgePublicationTest` 覆盖各类用户的列表/详情/分类/搜索权限、旧文迁移、旧后台行为、公开审核、slug 语言唯一、撤回、幂等状态、服务端 XSS 清洗、相对资源解析及回滚门禁。

2026-09-14 本地验证环境为 PHP 8.4.21 / SQLite：全量通过 347 个测试、1864 次断言；知识库与升级检查定向通过 32 个测试、447 次断言。`composer validate --no-check-publish --no-check-all` 和 `git diff --check` 通过。

本轮未验证 MySQL 迁移及 PHP 8.2 Swoole 生产镜像，合并部署前仍须由对应目标 CI 完成；本地 PHP/SQLite 通过不能替代这两项检查。依赖审计服务的本机请求超时，完整 `composer audit --locked` 也应在联网 CI 中复核，不能把安装成功视为安全审计通过。
