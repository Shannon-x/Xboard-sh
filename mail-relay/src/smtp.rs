//! SMTP：账号来源、连接池、错误分类。
//!
//! 账号优先读 Redis 哈希 `mail:relay:config`（面板保存邮件设置 / 投递时推过来，和面板后台的 email_* 设置是同一份），
//! 没有再读环境变量 RELAY_SMTP_*。每 10 秒看一次版本号，变了就换一个新连接池，正在发的信用旧池发完。

use crate::config::Config;
use lettre::transport::smtp::PoolConfig;
use lettre::transport::smtp::authentication::Credentials;
use lettre::transport::smtp::client::{Tls, TlsParameters};
use lettre::{AsyncSmtpTransport, Tokio1Executor};
use std::collections::HashMap;
use std::time::Duration;

#[derive(Clone, PartialEq, Eq)]
pub struct SmtpConfig {
    pub host: String,
    pub port: u16,
    /// 面板里的写法：ssl / tls / starttls / 空
    pub encryption: String,
    pub username: String,
    pub password: String,
    /// 面板推配置时带的版本号（内容摘要），用来判断要不要重建连接池
    pub version: String,
}

impl std::fmt::Debug for SmtpConfig {
    // 不打印密码
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SmtpConfig")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("encryption", &self.encryption)
            .field("username", &self.username)
            .field("version", &self.version)
            .finish()
    }
}

impl SmtpConfig {
    pub fn from_map(m: &HashMap<String, String>) -> Option<Self> {
        let host = m.get("host")?.trim().to_string();
        if host.is_empty() {
            return None;
        }
        Some(Self {
            host,
            port: m
                .get("port")
                .and_then(|p| p.trim().parse().ok())
                .unwrap_or(587),
            encryption: m
                .get("encryption")
                .cloned()
                .unwrap_or_default()
                .trim()
                .to_lowercase(),
            username: m.get("username").cloned().unwrap_or_default(),
            password: m.get("password").cloned().unwrap_or_default(),
            version: m.get("version").cloned().unwrap_or_default(),
        })
    }

    pub fn from_env() -> Option<Self> {
        let mut m = HashMap::new();
        for (k, env) in [
            ("host", "RELAY_SMTP_HOST"),
            ("port", "RELAY_SMTP_PORT"),
            ("encryption", "RELAY_SMTP_ENCRYPTION"),
            ("username", "RELAY_SMTP_USERNAME"),
            ("password", "RELAY_SMTP_PASSWORD"),
        ] {
            if let Ok(v) = std::env::var(env) {
                m.insert(k.to_string(), v);
            }
        }
        m.insert("version".into(), "env".into());
        Self::from_map(&m)
    }

    /// 465 是直接 TLS；其余端口只要面板填了 ssl / tls / starttls 就强制 STARTTLS
    /// （面板现网填的是 ssl + 587，PHP 那边 Symfony 实际也是走 STARTTLS）；没填加密就有 STARTTLS 用、没有就明文。
    pub fn tls_mode(&self) -> &'static str {
        if self.port == 465 {
            "wrapper"
        } else if matches!(self.encryption.as_str(), "ssl" | "tls" | "starttls") {
            "required"
        } else {
            "opportunistic"
        }
    }

    pub fn build(&self, cfg: &Config) -> anyhow::Result<AsyncSmtpTransport<Tokio1Executor>> {
        let params = TlsParameters::new(self.host.clone())?;
        let tls = match self.tls_mode() {
            "wrapper" => Tls::Wrapper(params),
            "required" => Tls::Required(params),
            _ => Tls::Opportunistic(params),
        };
        let mut b = AsyncSmtpTransport::<Tokio1Executor>::builder_dangerous(self.host.clone())
            .port(self.port)
            .tls(tls)
            .timeout(Some(cfg.smtp_timeout))
            // OCI 闲置几十秒就断：池里的连接闲置 25 秒就主动丢掉，取用时 lettre 还会先确认连接活着
            .pool_config(
                PoolConfig::new()
                    .max_size(cfg.connections as u32)
                    .idle_timeout(Duration::from_secs(25)),
            );
        if !self.username.is_empty() {
            b = b.credentials(Credentials::new(
                self.username.clone(),
                self.password.clone(),
            ));
        }
        Ok(b.build())
    }
}

/// 投递失败的归类，决定重试与回报给面板的 class
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Failure {
    /// 5xx：对方明确拒收（用户不存在、被抑制……），不重试
    Permanent,
    /// 4xx、超时、连接被断：退避重试
    Transient,
    /// 服务商在限流（421 / 451 / 4.7.x 里带 rate / limit / throttl）：退避重试，并让批量减速
    Throttled,
    /// 我们自己的问题：认证失败、TLS、连不上服务器。重试，最后回报 config（面板不计入用户）
    Config,
}

pub fn classify(err: &lettre::transport::smtp::Error) -> Failure {
    let text = err.to_string().to_lowercase();
    let code = err.status().map(|c| c.to_string());
    classify_parts(
        code.as_deref(),
        err.is_permanent(),
        err.is_transient(),
        err.is_tls(),
        &text,
    )
}

pub fn classify_parts(
    code: Option<&str>,
    permanent: bool,
    transient: bool,
    tls: bool,
    text: &str,
) -> Failure {
    if matches!(code, Some("535") | Some("534") | Some("530") | Some("538"))
        || text.contains("authenticat")
    {
        return Failure::Config;
    }
    if tls {
        return Failure::Config;
    }
    if transient {
        let throttle_words = ["rate", "limit", "throttl", "too many", "try again later"];
        if matches!(code, Some("421") | Some("451") | Some("452"))
            && throttle_words.iter().any(|w| text.contains(w))
        {
            return Failure::Throttled;
        }
        return Failure::Transient;
    }
    if permanent {
        // OCI 的抑制名单回 254 4.7.1 这种怪码时 lettre 也可能算成 permanent；按面板的口径都算对方拒收
        return Failure::Permanent;
    }
    if text.contains("connection refused")
        || text.contains("dns")
        || text.contains("resolve")
        || text.contains("no route")
    {
        return Failure::Config;
    }
    Failure::Transient
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn panel_ssl_on_587_means_starttls() {
        let mut m = HashMap::new();
        m.insert("host".to_string(), "smtp.example.test".to_string());
        m.insert("port".to_string(), "587".to_string());
        m.insert("encryption".to_string(), "SSL".to_string());
        let c = SmtpConfig::from_map(&m).unwrap();
        assert_eq!(c.tls_mode(), "required");
        m.insert("port".to_string(), "465".to_string());
        assert_eq!(SmtpConfig::from_map(&m).unwrap().tls_mode(), "wrapper");
        m.insert("port".to_string(), "25".to_string());
        m.insert("encryption".to_string(), "".to_string());
        assert_eq!(
            SmtpConfig::from_map(&m).unwrap().tls_mode(),
            "opportunistic"
        );
    }

    #[test]
    fn debug_never_prints_password() {
        let mut m = HashMap::new();
        m.insert("host".to_string(), "h".to_string());
        m.insert("password".to_string(), "s3cret-value".to_string());
        let c = SmtpConfig::from_map(&m).unwrap();
        assert!(!format!("{c:?}").contains("s3cret-value"));
    }

    #[test]
    fn classification() {
        assert_eq!(
            classify_parts(Some("535"), true, false, false, "535 authentication failed"),
            Failure::Config
        );
        assert_eq!(
            classify_parts(Some("550"), true, false, false, "550 5.1.1 user unknown"),
            Failure::Permanent
        );
        assert_eq!(
            classify_parts(
                Some("421"),
                false,
                true,
                false,
                "421 4.7.0 rate limit exceeded"
            ),
            Failure::Throttled
        );
        assert_eq!(
            classify_parts(
                Some("421"),
                false,
                true,
                false,
                "421 timeout waiting for data from client"
            ),
            Failure::Transient
        );
        assert_eq!(
            classify_parts(None, false, false, true, "tls handshake"),
            Failure::Config
        );
        assert_eq!(
            classify_parts(None, false, false, false, "connection refused"),
            Failure::Config
        );
        assert_eq!(
            classify_parts(None, false, false, false, "unexpected eof"),
            Failure::Transient
        );
    }
}
