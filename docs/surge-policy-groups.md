# Surge 订阅分组（地区 / 线路倍率 / AI）

工单 #5724：Surge 订阅里所有节点堆在一个大列表，客户希望像 Clash 一样按地区、按 3x/6x/三网优化分组，组内能自动选。

## 生成什么

模板 `[Proxy Group]` 段写 `$proxy_groups`，由 `app/Support/SurgePolicyGroups.php` 按节点名生成：

| 组 | 类型 | 内容 |
|---|---|---|
| `Proxy` | select | 自动选择、故障转移、各地区组、各线路组、DIRECT（不再直接列全部节点） |
| `自动选择` / `故障转移` | url-test / fallback | 不含公告类节点、不可直连落地、cf 加速、5x/6x/7x/10x（与 Clash「自动选择」同口径） |
| `🇭🇰 香港节点` 等 6 个地区 + `🌍 其他地区` | select | 第一项是该地区自动组（url-test，同样不含 cf/高倍率），后面是该地区全部节点可手动选 |
| `🚇 3x 中转节点` `🛣️ 6x 专线节点` `🌐 10x 三网优化` `☁️ cf加速节点` | select | 第一项是该线路的自动组，后面是全部节点 |
| `🤖 AI服务` | select | 默认 `🤖 AI优选`（fallback：🧠 AI自动 → 日本自动 → 美国自动 → 台湾自动 → 新加坡自动），也可选地区组 |

地区、排除项的匹配规则照抄 Clash Meta 模板（数据库 `clashmeta`）。Surge 不支持组里写正则让客户端筛，所以成员在服务端算好写死；
没有节点的组整组不发，并从引用它的组里摘掉（Surge 碰到空组或引用不存在的组会整份托管配置报错）。
`Proxy` 和 `🤖 AI服务` 被规则引用，一定会生成。

模板里没有 `$proxy_groups` 的（现网旧模板只写 `$proxy_group`）行为不变，所以先换镜像、再换模板，两步互不依赖。

## 上线

1. 换镜像（含本改动）后，现网输出不变。
2. 备份并替换数据库里的 Surge 模板（后台「订阅模板」里粘贴 `resources/rules/default.surge.conf` 也可以）：

```
docker exec index-web-1 php artisan tinker --execute="file_put_contents('/tmp/surge-tpl-backup.conf', App\Models\SubscribeTemplate::getContent('surge')); App\Models\SubscribeTemplate::setContent('surge', file_get_contents(base_path('resources/rules/default.surge.conf')));"
```

3. 用 Surge 6 真机更新托管配置，看策略组是否齐、`🤖 AI服务` 是否能打开 chatgpt.com。

## 回滚

只回模板即可：

```
docker exec index-web-1 php artisan tinker --execute="App\Models\SubscribeTemplate::setContent('surge', file_get_contents('/tmp/surge-tpl-backup.conf'));"
```

## 对现有 Surge 用户的影响

- 托管配置下次更新（`interval=43200`，或手动更新）后生效。之前在 `Proxy` 里手动选中某个节点的，会回到默认的「自动选择」，要到对应地区组里重新选。
- `自动选择` 不再测速 6x/10x/cf 节点（原来是全部节点一起测），按量用户不会被自动选到高倍率线路上多扣流量。
- 新增 AI 域名规则走 `🤖 AI服务`（原来走 `Proxy`）。
