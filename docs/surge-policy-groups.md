# Surge 订阅分组（地区 / 线路倍率 / AI）

工单 #5724：Surge 订阅里所有节点堆在一个大列表，客户希望像 Clash 一样按地区、按 3x/6x/三网优化分组，组内能自动选。

## 生成什么

模板 `[Proxy Group]` 段写 `$proxy_groups`，由 `app/Support/SurgePolicyGroups.php` 按节点名生成：

| 组 | 类型 | 内容 |
|---|---|---|
| `Proxy` | select | 自动选择、故障转移、各地区组、各线路组、DIRECT（不再直接列全部节点） |
| `自动选择` / `故障转移` | url-test / fallback | 不含公告类节点、不可直连落地、cf 加速、5x/6x/7x/10x（与 Clash「自动选择」同口径） |
| `🇭🇰 香港节点` 等 6 个地区 + `🌍 其他地区` | select | 第一项是该地区自动组（Surge 6 用 smart，旧版 url-test，`hidden=true`，同样不含 cf/高倍率），后面是该地区全部节点可手动选 |
| `🚇 3x 中转节点` `🛣️ 6x 专线节点` `🌐 10x 三网优化` `☁️ cf加速节点` | select | 第一项是该线路的自动组，后面是全部节点 |
| `🤖 AI服务` | select | 默认 `🤖 AI优选`（fallback：🧠 AI自动 → 日本自动 → 美国自动 → 台湾自动 → 新加坡自动），也可选地区组 |
| `📲 电报消息` | select | 默认跟 `Proxy`，可换地区 |
| `🎞️ 国际流媒体` | select | 默认 `🎬 解锁节点`（Clash 模板同一份解锁名单，不含 cf/高倍率），可换日本等地区 |
| `🐟 漏网之鱼` | select | 没命中任何规则的流量，默认 `Proxy`，可改 DIRECT |

地区、排除项的匹配规则照抄 Clash Meta 模板（数据库 `clashmeta`）。成员在服务端算好写死；
没有节点的组整组不发，并从引用它的组里摘掉（Surge 碰到空组或引用不存在的组会整份托管配置报错）。
`Proxy`、`🤖 AI服务`、`📲 电报消息`、`🎞️ 国际流媒体`、`🐟 漏网之鱼` 被规则引用，一定会生成。

## 参考了哪些项目

| 来源 | 照搬的做法 | 没用的做法和原因 |
|---|---|---|
| Surge 官方知识库「使用来自代理服务商的线路」（kb.nssurge.com） | 地区组用 `smart` 自动优选并故障切换；资源池类的组设 `hidden=true` | `policy-path` + `include-other-group` + `policy-regex-filter` 让客户端按正则筛：手册没写正则引擎是否支持 Clash 模板里的 lookahead，而且筛不到节点时组是空的，整份配置会报错，所以改在服务端算 |
| SukkaW/Surge（ruleset.skk.moe，社区最常用的 Surge 规则集之一） | AI、Telegram、国际流媒体三套规则集；不含 IP 的规则在前、IP 规则在后 | 没换掉 Loyalsoldier 的直连/代理/广告集，保持和 Clash、sing-box 模板同源，减少两边行为差异 |
| bunizao/TutuBetterRules（Surge.conf） | 主选择里放 自动/故障转移/各地区；服务组 select、第一项为默认；自动组 `interval=600` | 它的 YouTube/Netflix/TikTok 分别一个组：我们节点解锁情况大体一致，合成一个 `🎞️ 国际流媒体` 够用，组太多又会变乱 |
| ACL4SSR（订阅转换常用模板） | 「🚀 节点选择 / ♻️ 自动选择 / 🐟 漏网之鱼」结构，兜底组可改直连 | 苹果/微软/谷歌服务组：现网这几类已分别走直连或代理，不额外加组 |

我们自己的规矩（这些项目里没有）：自动类的组排除 cf 加速和 5x/6x/7x/10x，免得按量用户被自动选到高倍率线路；3x/6x/10x/cf 线路组是工单要的。

`smart` 只给 Surge 6（内核 6.12.0+，即能收 VLESS 的版本）下发；`smart` 官方说明不支持嵌套组、固定 5 分钟测一次，所以只用在只装节点的自动子组上。顶层 `自动选择` 和 `🧠 AI自动`（要用 chatgpt.com 测速）仍是 url-test。

模板里没有 `$proxy_groups` 的（现网旧模板只写 `$proxy_group`）行为不变，所以先换镜像、再换模板，两步互不依赖。

## 上线

1. 换镜像（含本改动）后，现网输出不变。
2. 备份并替换数据库里的 Surge 模板（后台「订阅模板」里粘贴 `resources/rules/default.surge.conf` 也可以）：

```
docker exec index-web-1 php artisan tinker --execute="file_put_contents('/tmp/surge-tpl-backup.conf', App\Models\SubscribeTemplate::getContent('surge')); App\Models\SubscribeTemplate::setContent('surge', file_get_contents(base_path('resources/rules/default.surge.conf')));"
```

3. 用 Surge 6 真机更新托管配置，看策略组是否齐、`🤖 AI服务` 是否能打开 chatgpt.com、ruleset.skk.moe 的规则集能否下载（新增的外部依赖，国内下载不了时这几条规则不生效，流量落到 `🐟 漏网之鱼` 走代理）。

## 回滚

只回模板即可：

```
docker exec index-web-1 php artisan tinker --execute="App\Models\SubscribeTemplate::setContent('surge', file_get_contents('/tmp/surge-tpl-backup.conf'));"
```

## 对现有 Surge 用户的影响

- 托管配置下次更新（`interval=43200`，或手动更新）后生效。之前在 `Proxy` 里手动选中某个节点的，会回到默认的「自动选择」，要到对应地区组里重新选。
- `自动选择` 不再测速 6x/10x/cf 节点（原来是全部节点一起测），按量用户不会被自动选到高倍率线路上多扣流量。
- 新增 AI、电报、国际流媒体规则分别走 `🤖 AI服务`、`📲 电报消息`、`🎞️ 国际流媒体`（原来走 `Proxy`），默认值都是自动选好的线路。
