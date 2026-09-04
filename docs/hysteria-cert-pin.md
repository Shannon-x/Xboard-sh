# Hysteria2 证书指纹固定与「允许不安全」

> 一句话结论:**两者不冲突,但「指纹要不要配合 insecure」是按内核而异的**。
> 大多数客户端(小火箭 / mihomo / Stash / sing-box 1.13+ / 新版 Xray)用指纹**替代**证书链校验,
> 站长不需要打开「允许不安全」;只有 hysteria 原生内核和没有指纹输入口的客户端才需要跳过链校验。
> 面板按客户端自动处理,站长通常什么都不用管。

## 一、为什么不冲突

两者管的是不同的事:

| | 管什么 |
| --- | --- |
| `insecure` | 关掉「证书链 + 主机名」校验,即**不再追究是谁签发的** |
| 指纹(`pinSHA256` / `fingerprint` / …) | 要求对端证书**必须是这一张**(SHA256 精确匹配) |

`insecure=1 + 指纹` 并不等于不安全:安全性由「证书哈希精确匹配」提供,自签场景下强度不低于
CA 链校验(反而更强,不依赖任何第三方 CA)。真正危险的是**只开 insecure、没有指纹**。

但**是否需要额外开 insecure,取决于客户端怎么实现指纹**,分两派:

- **叠加派 —— hysteria 原生内核**:`app/cmd/client.go` 的 `fillTLSConfig()` 里
  `InsecureSkipVerify` 只跟 `tls.insecure` 走,指纹另挂在 `VerifyPeerCertificate` 上。
  而 Go 的链校验一旦失败就直接中断握手、回调根本不执行 ——
  所以自签证书下**必须** `insecure: true` + `pinSHA256` 同时给。
- **替代派 —— 小火箭 / mihomo / Stash / sing-box 1.13+ / 新版 Xray**:
  配了指纹,内核自己接管校验(内部置 `InsecureSkipVerify` 再挂指纹回调)。
  这时**不该**再开 insecure —— 开了反而可能让客户端跳过指纹校验,把站长开启的安全特性废掉。

还有**第三类:根本没有指纹输入口**(Surge / Loon / hysteria v1)。对它们只能跳过链校验,否则连不上。

## 二、各客户端支持情况(hysteria2)

| 客户端 / 内核 | 指纹字段 | 哈希对象 / 编码 | 需要 insecure | 面板下发 |
| --- | --- | --- | --- | --- |
| hysteria 原生内核(v2rayN 等) | URI `pinSHA256` | 证书 DER / 小写 hex | **需要** | 指纹 + `insecure=1` |
| Shadowrocket(小火箭) | TLS 设置页「SHA256」;URI `pinSHA256` | 证书 DER / hex | 不需要 | 指纹,insecure 听站长的 |
| mihomo (Clash.Meta) | `fingerprint`(proxy 级) | 证书 DER / hex | 不需要 | 指纹 + `skip-cert-verify` 兜底老内核 |
| Stash | `fingerprint`(闭源核,未实测) | 同上 | 不需要 | 同上 |
| sing-box ≥ 1.13 | `certificate_public_key_sha256` | **公钥 SPKI / base64** | 不需要 | 指纹(低版本退化为 `insecure`) |
| Xray-core(有 hy2 出入站) | `tlsSettings.pinnedPeerCertSha256` | 证书 DER / **64 位小写 hex** | 不需要 | 客户端从 URI 的 `pinSHA256` 映射 |
| Surge | 无(hy2) | — | — | 只能 `skip-cert-verify=true` |
| Loon | 无 | — | — | 只能 `skip-cert-verify=true` |

几个容易踩的点:

- **格式绝不能混用**。填错不会报错、只会「配置看着正常但永远连不上」。
  hysteria / mihomo / Xray 要的是**证书 DER 的 hex**;sing-box 要的是**公钥 SPKI 的 base64**。
  面板同时算并存两个值:`pinned_peer_cert_sha256` 与 `pinned_public_key_sha256`,下发时各取所需。
- **给 Xray 系客户端发 base64 会直接崩**:Xray 严格 hex 解码,拿到 base64 会报
  `encoding/hex: invalid byte`(3x-ui 踩过这个坑)。所以 URI 里的 `pinSHA256` 一律是 64 位小写 hex。
- `pcs` 是 VLESS / Trojan 分享链接的约定,hysteria2 URI 规范里没有这个参数,不要塞。
- **Xray-core 是有 hysteria 出入站的**(`proxy/hysteria/{client,server}.go`),
  它的 hy2 TLS 走标准 `streamSettings.tlsSettings`。早期版本自签证书 + `pinnedPeerCertSha256`
  连不上是 bug([Xray-core#5655](https://github.com/XTLS/Xray-core/issues/5655)),已修复。

## 三、2026-09 修复的问题

**症状**:节点开了证书指纹后,在小火箭上连不上;手动在小火箭里填 SHA256 却能连上。

**原因**:`Shadowrocket.php` 压根没读 `pinned_*`,只发 `insecure = allow_insecure`。
站长把「允许不安全」关着(默认值)时,小火箭既拿不到指纹、也不跳过链校验,自签证书必然握手失败。
Surge / Loon 同样不发指纹,也是只读这个开关。

**修复**:

1. **小火箭下发标准 `pinSHA256`(小写 hex)**,`insecure` 尊重站长的开关 ——
   小火箭用指纹替代链校验,强开 insecure 反而可能让它跳过指纹校验。
2. Surge / Loon 没有指纹输入口,有指纹时强制 `skip-cert-verify=true`,否则节点不可用。
3. hysteria 原生内核走的通用 URI 保持「指纹 + `insecure=1`」—— 它的指纹是叠加式的,必须配合。
4. `CertPinHelper::normalizePin()` 归一指纹格式(大小写、冒号、短横线都能吃,
   `openssl x509 -fingerprint -sha256` 的大写冒号输出可直接用),非法值当作没有指纹处理。
5. hysteria **v1** 出站没有 `fingerprint` 字段,ClashMeta / Stash 不再写它,但仍给 `skip-cert-verify`。
6. 后端**不再**强写 `allow_insecure=true`。之前按「原生内核的规则」一刀切,会削弱替代派客户端。

不变量由 `tests/Feature/HysteriaCertPinTest.php` 锁住:
**有指纹时,每个客户端要么收到指纹、要么被放行跳过校验,不允许两者皆无**;
支持指纹的客户端不得被强开 insecure;没有指纹时不擅自跳过校验。

## 四、还需要实测确认的点

- 小火箭是否解析 URI 里的 `pinSHA256`(它的 UI 有 SHA256 输入口、且手填可用;
  URI 参数名按 hysteria2 官方规范下发)。若实测发现不认,把节点的「允许不安全」打开即可恢复连通。
- Stash 的 proxy 级 `fingerprint` 是否对 hysteria2 生效(有 `skip-cert-verify` 兜底,不生效也不会断连)。
- Surge 的 `server-cert-fingerprint-sha256` 是否支持 hysteria2 —— 未知键有导致 Surge 拒解析整行的风险,
  目前**不下发**,实测通过后再加(格式是大写冒号分隔 hex)。
- 验证指纹是否真的生效:把节点证书换掉一张,支持指纹的客户端应该**拒绝连接**;
  照样能连说明它只是在跳过校验。
