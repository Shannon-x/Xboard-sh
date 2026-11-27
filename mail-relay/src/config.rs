//! 运行参数：全部来自环境变量（容器里写在 compose 的 environment）。SMTP 账号优先读 Redis 里面板推过来的那份，
//! 见 `smtp::SmtpConfig::load`。

use std::time::Duration;

#[derive(Clone, Debug)]
pub struct Config {
    /// redis://127.0.0.1:6379/0 或 redis+unix:///data/redis.sock（现网面板用 unix socket）
    pub redis_url: String,
    /// 面板 Laravel 的 Redis key 前缀（config('database.redis.options.prefix')，现网 xboard_database_）。
    /// PHP 写的 key 都自动带这个前缀，这里要拼成一样的才找得到
    pub key_prefix: String,
    /// 消费者组里的名字。只跑一个实例：重启时靠它把自己没确认的信重新领回来
    pub consumer: String,
    /// SMTP 并发连接数 = 并发投递的 worker 数
    pub connections: usize,
    /// 其中这么多个 worker 永远不碰批量通道：批量再多，即时 / 交易也总有空闲连接
    pub reserved: usize,
    /// 全局速率上限（封 / 分钟，三条通道合计）。0 = 不限。
    /// OCI 付费账户默认每分钟 18000 封（按收件人计），这里默认 600，远低于上限
    pub rate_per_minute: u32,
    /// 批量通道最多占全局速率的比例（只在 rate_per_minute > 0 时生效）
    pub bulk_share: f64,
    /// 一封信最多投递几次（含第一次）。临时失败按 backoff 退避重试
    pub max_attempts: u32,
    pub backoff: Vec<Duration>,
    /// 服务商限流（421 / 451 / 452 带限流字样，或 455）时最多投递几次、每次等多久。
    /// 限流不是这封信的问题，所以次数多、间隔长，不和普通临时失败共用
    pub throttle_max_attempts: u32,
    pub throttle_backoff: Vec<Duration>,
    /// 滚动 24 小时内本服务最多发多少封（按收件人计，和 OCI 的 max-emails-day 一样）。0 = 不限。
    /// OCI 付费账户默认 50000；面板自己直发的（没开投递服务的通道）也占这个额度，所以默认只用 45000
    pub daily_limit: u32,
    /// 24 小时额度里给即时 / 交易留的部分：批量用到 daily_limit - daily_bulk_reserve 就停，验证码和收据还能发
    pub daily_bulk_reserve: u32,
    /// 单封邮件原文最大字节数（含头和 Base64 附件）。OCI 默认 2 MB，超了对方会拒收；
    /// 这里先拦下，按「我们这边的问题」回报，不算成用户退信。0 = 不拦
    pub max_message_bytes: usize,
    /// 面板写大邮件（带 PDF）的共享目录，通道里只放路径
    pub spool_dir: String,
    pub http_addr: String,
    /// 单封 SMTP 投递超时
    pub smtp_timeout: Duration,
}

fn env_or(name: &str, default: &str) -> String {
    std::env::var(name)
        .ok()
        .filter(|v| !v.trim().is_empty())
        .unwrap_or_else(|| default.to_string())
}

fn parse_secs(s: &str) -> Vec<Duration> {
    s.split(',')
        .filter_map(|s| s.trim().parse::<u64>().ok())
        .map(Duration::from_secs)
        .collect()
}

fn step(list: &[Duration], failures: u32) -> Duration {
    let i = (failures.max(1) as usize - 1).min(list.len() - 1);
    list[i]
}

fn env_parse<T: std::str::FromStr>(name: &str, default: T) -> T {
    std::env::var(name)
        .ok()
        .and_then(|v| v.trim().parse().ok())
        .unwrap_or(default)
}

impl Config {
    pub fn from_env() -> anyhow::Result<Self> {
        let connections = env_parse("RELAY_CONNECTIONS", 8usize).clamp(1, 64);
        let reserved = env_parse("RELAY_RESERVED", 2usize).min(connections.saturating_sub(1));
        let bulk_share = env_parse("RELAY_BULK_SHARE", 0.8f64).clamp(0.05, 1.0);
        let backoff = parse_secs(&env_or("RELAY_BACKOFF", "3,10,30,120"));
        if backoff.is_empty() {
            anyhow::bail!("RELAY_BACKOFF 至少要有一个数字（秒）");
        }
        let throttle_backoff = parse_secs(&env_or("RELAY_THROTTLE_BACKOFF", "60,120,300,600,900"));
        if throttle_backoff.is_empty() {
            anyhow::bail!("RELAY_THROTTLE_BACKOFF 至少要有一个数字（秒）");
        }
        let daily_limit = env_parse("RELAY_DAILY_LIMIT", 45_000u32);
        Ok(Self {
            redis_url: env_or("RELAY_REDIS_URL", "redis://127.0.0.1:6379/0"),
            key_prefix: env_or("RELAY_KEY_PREFIX", ""),
            consumer: env_or("RELAY_CONSUMER", "relay-1"),
            connections,
            reserved,
            rate_per_minute: env_parse("RELAY_RATE_PER_MINUTE", 600u32),
            bulk_share,
            max_attempts: env_parse("RELAY_MAX_ATTEMPTS", 4u32).max(1),
            backoff,
            throttle_max_attempts: env_parse("RELAY_THROTTLE_MAX_ATTEMPTS", 10u32).max(1),
            throttle_backoff,
            daily_limit,
            daily_bulk_reserve: env_parse("RELAY_DAILY_BULK_RESERVE", 5_000u32).min(daily_limit),
            max_message_bytes: env_parse("RELAY_MAX_MESSAGE_BYTES", 2_000_000usize),
            spool_dir: env_or("RELAY_SPOOL_DIR", "/www/storage/mail-spool"),
            http_addr: env_or("RELAY_HTTP_ADDR", "0.0.0.0:9810"),
            smtp_timeout: Duration::from_secs(env_parse("RELAY_SMTP_TIMEOUT", 30u64).max(5)),
        })
    }

    pub fn key(&self, name: &str) -> String {
        format!("{}mail:relay:{}", self.key_prefix, name)
    }

    /// 第 n 次失败之后等多久再试（n 从 1 开始）
    pub fn backoff_after(&self, failures: u32) -> Duration {
        step(&self.backoff, failures)
    }

    /// 被服务商限流的第 n 次之后等多久
    pub fn throttle_backoff_after(&self, failures: u32) -> Duration {
        step(&self.throttle_backoff, failures)
    }

    /// 这条通道在滚动 24 小时里最多能发多少（0 = 不限）
    pub fn daily_cap(&self, lane: Lane) -> u32 {
        match lane {
            Lane::Bulk => self.daily_limit.saturating_sub(self.daily_bulk_reserve),
            _ => self.daily_limit,
        }
    }
}

/// 三条通道，名字与面板 App\Services\Mail\MailLane 去掉 mail_ 前缀后一致。顺序就是优先级
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub enum Lane {
    Instant,
    Transactional,
    Bulk,
}

impl Lane {
    pub const ALL: [Lane; 3] = [Lane::Instant, Lane::Transactional, Lane::Bulk];

    pub fn as_str(self) -> &'static str {
        match self {
            Lane::Instant => "instant",
            Lane::Transactional => "transactional",
            Lane::Bulk => "bulk",
        }
    }

    pub fn parse(s: &str) -> Option<Lane> {
        Lane::ALL.into_iter().find(|l| l.as_str() == s)
    }

    pub fn stream_key(self, cfg: &Config) -> String {
        cfg.key(&format!("stream:{}", self.as_str()))
    }
}

pub const GROUP: &str = "relay";

#[cfg(test)]
mod tests {
    use super::*;

    fn cfg() -> Config {
        Config {
            redis_url: String::new(),
            key_prefix: "xboard_database_".into(),
            consumer: "t".into(),
            connections: 8,
            reserved: 2,
            rate_per_minute: 0,
            bulk_share: 0.8,
            max_attempts: 4,
            backoff: vec![Duration::from_secs(3), Duration::from_secs(10)],
            throttle_max_attempts: 10,
            throttle_backoff: vec![Duration::from_secs(60)],
            daily_limit: 45_000,
            daily_bulk_reserve: 5_000,
            max_message_bytes: 2_000_000,
            spool_dir: String::new(),
            http_addr: String::new(),
            smtp_timeout: Duration::from_secs(30),
        }
    }

    #[test]
    fn keys_carry_the_laravel_prefix() {
        assert_eq!(
            Lane::Bulk.stream_key(&cfg()),
            "xboard_database_mail:relay:stream:bulk"
        );
        assert_eq!(cfg().key("results"), "xboard_database_mail:relay:results");
    }

    #[test]
    fn bulk_leaves_part_of_the_daily_quota_for_codes_and_receipts() {
        let c = cfg();
        assert_eq!(c.daily_cap(Lane::Bulk), 40_000);
        assert_eq!(c.daily_cap(Lane::Instant), 45_000);
        assert_eq!(c.daily_cap(Lane::Transactional), 45_000);
    }

    #[test]
    fn backoff_sticks_to_the_last_step() {
        let c = cfg();
        assert_eq!(c.backoff_after(1), Duration::from_secs(3));
        assert_eq!(c.backoff_after(2), Duration::from_secs(10));
        assert_eq!(c.backoff_after(9), Duration::from_secs(10));
    }
}
