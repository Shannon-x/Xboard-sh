# Hysteria2 证书指纹固定与「允许不安全」

> 一句话结论:**两者不冲突,而且在指纹固定模式下必须同时生效**。
> 面板已经把「允许不安全」变成派生值,管理员不需要(也不能)再手动决定它。

## 一、为什么不冲突

很多人以为 `pinSHA256`(证书指纹)和 `insecure`(允许不安全)是二选一,其实它们管的是两件事:

| | 管什么 | 底层 |
| --- | --- | --- |
| `insecure` | 关掉「证书链 + 主机名」校验,即**不再追究是谁签发的** | Go 的 `InsecureSkipVerify` |
| `pinSHA256` | 要求对端证书**必须是这一张**(SHA256 精确匹配) | `VerifyPeerCertificate` 回调 |

关键在 Go 的执行顺序:**链校验一旦失败就立刻中断握手,`VerifyPeerCertificate` 根本不会被调用**。
所以在面板自签证书(`cert_mode=remote`)的场景下:

- 只配指纹、不开 insecure → 握手在链校验阶段就挂了,指纹永远没机会生效 → **节点连不上**
- 指纹 + insecure 一起 → 链校验被跳过,指纹接手校验 → 正常连接,且安全性**不低于**普通 CA 校验
  (自签场景下反而更强:不依赖任何第三方 CA,只认这一张证书)

真正危险的是**只开 insecure、没有指纹** —— 那才是任何中间人都能顶替。

各客户端的具体语义分三派:

- **叠加派**(hysteria 官方客户端):pin 之外必须自己开 `insecure`。
- **替代派**(mihomo 的 `fingerprint`、sing-box 1.13+ 的 `certificate_public_key_sha256`):
  内核在 pin 生效时自己置 `InsecureSkipVerify`,不必也不该再单独写 insecure。
- **信任锚派**(sing-box 的 `certificate` / `certificate_path`):把自签证书当 CA 喂进去,
  这时链校验照常走并且会通过,绝不能开 insecure。

## 二、各客户端支持情况(hysteria2)

| 客户端 | 指纹字段 | 哈希对象 / 编码 | 面板下发 |
| --- | --- | --- | --- |
| hysteria 官方客户端 / hysteria2:// 链接 | `pinSHA256` | 证书 DER / 小写 hex | ✅ + `insecure=1` |
| mihomo (Clash.Meta) | `fingerprint`(proxy 级) | 证书 DER / hex | ✅ + `skip-cert-verify` 兜底老内核 |
| Stash | `fingerprint`(闭源核,未实测) | 同上 | ✅ + `skip-cert-verify` 兜底 |
| sing-box ≥ 1.13 | `certificate_public_key_sha256` | **公钥 SPKI / base64** | ✅(低版本退化为 `insecure`) |
| Shadowrocket(小火箭) | **不支持** | — | ❌ 只能 `insecure=1` |
| Surge | **不支持**(hy2) | — | ❌ 只能 `skip-cert-verify=true` |
| Loon | **不支持** | — | ❌ 只能 `skip-cert-verify=true` |

注意两点:

- **哈希对象和编码各家不同**,填错不会报错、只会「配置看着正常但永远连不上」。
  所以面板同时算并存两个值:`pinned_peer_cert_sha256`(证书 DER)与
  `pinned_public_key_sha256`(公钥 SPKI),下发时各取所需、按需转 base64。
- **xray-core 根本没有 hysteria/hysteria2 出站**,所以 hy2 链接里不再下发 `pcs` 参数
  (那是 xray 的 `pinnedPeerCertSha256` 简写,对 hy2 是冗余,还可能让严格解析的客户端报错)。
  v2rayN / v2rayNG 的 hy2 是外挂 sing-box 或官方 hysteria 二进制跑的,按对应内核的规则下发。

## 三、2026-09 修复的问题

**症状**:节点开了证书指纹后,在 Shadowrocket / Surge / Loon 上连不上。

**原因**:`allow_insecure` 默认是 `false`,而带指纹的四个生成器
(General / ClashMeta / Stash / SingBox)会强制把它打开,不带指纹的三个
(Shadowrocket / Surge / Loon)只读 `allow_insecure` —— 拿到 `false` 就既没有指纹、
也不跳过校验,自签证书必然握手失败。管理员在界面上关着的那个开关,对前四家无效、对后三家致命。

**修复**:

1. `ManageController::syncCertPinToProtocolSettings()` 写入指纹的同时把
   `protocol_settings.*.allow_insecure` 一并置为 `true`。这是根因修复:
   即便某个订阅生成器(含第三方插件)没处理指纹,节点也不会挂。
2. Shadowrocket / Surge / Loon 的 hysteria 生成器改为「有指纹即跳过链校验」,双保险。
3. `CertPinHelper::normalizePin()` 归一指纹格式(大小写、冒号、短横线),
   非法值当作没有指纹处理 —— 绝不下发一个永远比不上的哈希。
4. hysteria **v1** 出站没有 `fingerprint` 字段,ClashMeta / Stash 不再写它,但仍给 `skip-cert-verify`;
   官方 v1 客户端也不支持 `pinSHA256`,只给 `insecure`。
5. 管理端:`cert_mode=remote` 且已有指纹时,「允许不安全」开关锁定为开并说明原因;
   切走该模式后不再显示历史指纹(以免管理员照抄一个已经失效的值)。

不变量由 `tests/Feature/HysteriaCertPinTest.php` 锁住:
**有指纹 ⇒ 每个客户端的输出里都必须跳过链校验;没有指纹 ⇒ 不擅自跳过。**

## 四、还需要实测确认的点

以下几条无法离线核实,建议建一个 `cert_mode=remote` 的 hy2 节点后逐个验证:

- Shadowrocket 是否已经能解析 hy2 链接里的 `pinSHA256`(目前按「不支持」处理,靠 `insecure` 保连通)。
- Stash 的 proxy 级 `fingerprint` 是否对 hysteria2 生效(有 `skip-cert-verify` 兜底,不生效也不会断连)。
- Surge 的 `server-cert-fingerprint-sha256` 是否支持 hysteria2 —— 未知键有导致 Surge 拒解析整行的风险,
  所以目前**不下发**,实测通过后再加(格式是大写冒号分隔 hex)。
- 真正验证指纹有没有生效的办法:把节点证书换掉一张,支持指纹的客户端应该**拒绝连接**;
  如果照样能连,说明该客户端只是在跳过校验。
