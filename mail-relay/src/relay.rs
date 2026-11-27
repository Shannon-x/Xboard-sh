//! 投递主流程：每条通道一个读取任务 → 有界队列 → N 个投递 worker（按 即时 > 交易 > 批量 取信，
//! 其中 reserved 个 worker 永远不碰批量）→ 共用的 SMTP 连接池。另有重试搬运、心跳、SMTP 配置热更新三个后台任务。

use crate::config::{Config, Lane};
use crate::daily::{self, Daily};
use crate::limiter::Limiter;
use crate::mail::Mail;
use crate::smtp::{self, Failure, SmtpConfig};
use crate::store::{self, Outcome, Store};
use lettre::address::Envelope;
use lettre::{Address, AsyncSmtpTransport, AsyncTransport, Tokio1Executor};
use std::collections::HashSet;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, RwLock};
use std::time::Duration;
use tokio::sync::watch;
use tracing::{debug, error, info, warn};

type Transport = AsyncSmtpTransport<Tokio1Executor>;

#[derive(Default)]
pub struct Metrics {
    pub sent: [AtomicU64; 3],
    pub failed_permanent: AtomicU64,
    pub failed_transient: AtomicU64,
    pub failed_config: AtomicU64,
    pub failed_throttled: AtomicU64,
    pub failed_internal: AtomicU64,
    pub failed_too_large: AtomicU64,
    pub expired: AtomicU64,
    /// 24 小时额度满了延后发的次数
    pub deferred: AtomicU64,
    pub retried: AtomicU64,
    pub duplicates: AtomicU64,
    pub throttle_events: AtomicU64,
    pub redis_errors: AtomicU64,
}

impl Metrics {
    fn failed(&self, class: &str) -> &AtomicU64 {
        match class {
            "permanent" => &self.failed_permanent,
            "config" => &self.failed_config,
            "throttled" => &self.failed_throttled,
            "internal" => &self.failed_internal,
            "too_large" => &self.failed_too_large,
            _ => &self.failed_transient,
        }
    }
}

fn lane_idx(l: Lane) -> usize {
    match l {
        Lane::Instant => 0,
        Lane::Transactional => 1,
        Lane::Bulk => 2,
    }
}

pub struct Shared {
    pub cfg: Config,
    pub store: Store,
    pub limiter: Limiter,
    pub daily: Daily,
    pub metrics: Metrics,
    smtp: RwLock<Option<(SmtpConfig, Transport)>>,
    /// 已经从 Redis 领出来、还没确认的条目（含排在内存队列里的）。重读自己名下的待确认条目时跳过它们，免得一封信同时发两遍
    inflight: Mutex<HashSet<String>>,
}

impl Shared {
    pub fn new(cfg: Config, store: Store) -> Arc<Self> {
        let limiter = Limiter::new(cfg.rate_per_minute, cfg.bulk_share);
        Arc::new(Self {
            cfg,
            store,
            limiter,
            daily: Daily::new(),
            metrics: Metrics::default(),
            smtp: RwLock::new(None),
            inflight: Mutex::new(HashSet::new()),
        })
    }

    pub fn smtp_version(&self) -> Option<String> {
        self.smtp
            .read()
            .unwrap()
            .as_ref()
            .map(|(c, _)| c.version.clone())
    }

    pub fn smtp_host(&self) -> Option<String> {
        self.smtp
            .read()
            .unwrap()
            .as_ref()
            .map(|(c, _)| c.host.clone())
    }

    pub fn inflight_len(&self) -> usize {
        self.inflight.lock().unwrap().len()
    }

    fn transport(&self) -> Option<Transport> {
        self.smtp.read().unwrap().as_ref().map(|(_, t)| t.clone())
    }

    /// 读 Redis 里的 SMTP 配置（没有就用环境变量），版本变了才重建连接池
    pub async fn reload_smtp(&self) {
        let from_redis = match self.store.smtp_config().await {
            Ok(m) => SmtpConfig::from_map(&m),
            Err(e) => {
                warn!(error = %e, "读 SMTP 配置失败，沿用当前配置");
                return;
            }
        };
        let Some(next) = from_redis.or_else(SmtpConfig::from_env) else {
            if self.smtp.read().unwrap().is_some() {
                warn!("SMTP 配置被删掉了，停止投递，等面板重新推送");
                *self.smtp.write().unwrap() = None;
            }
            return;
        };
        if self.smtp_version().as_deref() == Some(next.version.as_str())
            && self
                .smtp
                .read()
                .unwrap()
                .as_ref()
                .map(|(c, _)| c == &next)
                .unwrap_or(false)
        {
            return;
        }
        match next.build(&self.cfg) {
            Ok(t) => {
                info!(host = %next.host, port = next.port, tls = next.tls_mode(), version = %next.version, "SMTP 配置已载入");
                *self.smtp.write().unwrap() = Some((next, t));
            }
            Err(e) => error!(error = %e, host = %next.host, "SMTP 配置无效"),
        }
    }
}

/// 一封信最后怎么样了
#[derive(Debug, PartialEq, Eq)]
pub enum Verdict {
    Sent {
        code: String,
    },
    Expired,
    Duplicate,
    /// 24 小时额度满了：原样延后，不算一次投递
    Deferred {
        after: Duration,
    },
    Retry {
        class: Failure,
        after: Duration,
        code: Option<String>,
        error: String,
    },
    Failed {
        class: &'static str,
        code: Option<String>,
        error: String,
    },
}

pub fn failure_class(f: Failure) -> &'static str {
    match f {
        Failure::Permanent => "permanent",
        Failure::Transient => "transient",
        Failure::Throttled => "throttled",
        Failure::Config => "config",
    }
}

/// 失败之后：重试还是放弃（纯函数，方便测试）
pub fn after_failure(
    cfg: &Config,
    mail: &Mail,
    class: Failure,
    code: Option<String>,
    error: String,
    now: i64,
) -> Verdict {
    let attempts = mail.attempt + 1;
    // 被限流不是这封信的问题：次数放宽、间隔拉长（默认 1、2、5、10、15 分钟……共 10 次）
    let (max, after) = if class == Failure::Throttled {
        (
            cfg.throttle_max_attempts.max(cfg.max_attempts),
            cfg.throttle_backoff_after(attempts),
        )
    } else {
        (cfg.max_attempts, cfg.backoff_after(attempts))
    };
    if class == Failure::Permanent || attempts >= max {
        return Verdict::Failed {
            class: failure_class(class),
            code,
            error,
        };
    }
    // 重试时已经过期就不用排了
    if mail.not_after > 0 && now + after.as_secs() as i64 > mail.not_after {
        return Verdict::Failed {
            class: failure_class(class),
            code,
            error,
        };
    }
    Verdict::Retry {
        class,
        after,
        code,
        error,
    }
}

async fn load_eml(cfg: &Config, mail: &Mail) -> Result<Vec<u8>, String> {
    if let Some(e) = &mail.eml {
        return Ok(e.clone());
    }
    let name = mail.eml_path.as_deref().unwrap_or_default();
    // 只认 spool 目录下的文件名，不接受路径
    if name.contains('/') || name.contains('\\') || name.starts_with('.') {
        return Err(format!("bad eml_path {name:?}"));
    }
    let path = std::path::Path::new(&cfg.spool_dir).join(name);
    tokio::fs::read(&path)
        .await
        .map_err(|e| format!("read spool {}: {e}", path.display()))
}

async fn daily_persist(sh: &Shared, minute: i64, delta: i64) {
    if let Err(e) = sh.store.daily_add(minute, delta).await {
        // 只影响重启后的计数，内存里已经记上了
        sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
        warn!(error = %e, "写 24 小时额度计数失败");
    }
}

/// 超过服务商单封大小上限的信直接放弃：发出去也会被拒（5xx），面板会误记成用户退信
fn too_large(cfg: &Config, len: usize) -> Option<Verdict> {
    (cfg.max_message_bytes > 0 && len > cfg.max_message_bytes).then(|| Verdict::Failed {
        class: "too_large",
        code: None,
        error: format!(
            "message is {len} bytes, over the {} byte limit",
            cfg.max_message_bytes
        ),
    })
}

async fn deliver(sh: &Shared, mail: &Mail) -> Verdict {
    let now = store::now_secs();
    if mail.not_after > 0 && now > mail.not_after {
        return Verdict::Expired;
    }
    match sh.store.is_done(&mail.id).await {
        Ok(true) => return Verdict::Duplicate,
        Ok(false) => {}
        Err(e) => {
            sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
            return Verdict::Retry {
                class: Failure::Transient,
                after: sh.cfg.backoff_after(1),
                code: None,
                error: format!("redis: {e}"),
            };
        }
    }
    let eml = match load_eml(&sh.cfg, mail).await {
        Ok(e) => e,
        Err(e) => {
            return Verdict::Failed {
                class: "internal",
                code: None,
                error: e,
            };
        }
    };
    if let Some(v) = too_large(&sh.cfg, eml.len()) {
        return v;
    }
    let to: Address = match mail.to.parse() {
        Ok(a) => a,
        Err(e) => {
            return Verdict::Failed {
                class: "permanent",
                code: None,
                error: format!("bad recipient: {e}"),
            };
        }
    };
    let from: Address = match mail.from.parse() {
        Ok(a) => a,
        Err(e) => {
            return Verdict::Failed {
                class: "config",
                code: None,
                error: format!("bad sender: {e}"),
            };
        }
    };
    let envelope = match Envelope::new(Some(from), vec![to]) {
        Ok(e) => e,
        Err(e) => {
            return Verdict::Failed {
                class: "internal",
                code: None,
                error: e.to_string(),
            };
        }
    };
    let Some(transport) = sh.transport() else {
        return after_failure(
            &sh.cfg,
            mail,
            Failure::Config,
            None,
            "no SMTP config yet".into(),
            now,
        );
    };
    let minute = match sh.daily.take(&sh.cfg, mail.lane, now) {
        Ok(minute) => {
            daily_persist(sh, minute, 1).await;
            minute
        }
        Err(after) => return Verdict::Deferred { after },
    };
    sh.limiter.acquire(mail.lane).await;
    let limit = sh.cfg.smtp_timeout * 3;
    match tokio::time::timeout(limit, transport.send_raw(&envelope, &eml)).await {
        Ok(Ok(resp)) => Verdict::Sent {
            code: resp.code().to_string(),
        },
        Ok(Err(e)) => {
            let class = smtp::classify(&e);
            if class == Failure::Throttled {
                // 被限流的那次服务商没收下，不占 24 小时额度
                sh.daily.give_back(minute);
                daily_persist(sh, minute, -1).await;
            }
            let code = e.status().map(|c| c.to_string());
            after_failure(&sh.cfg, mail, class, code, e.to_string(), store::now_secs())
        }
        Err(_) => after_failure(
            &sh.cfg,
            mail,
            Failure::Transient,
            None,
            format!("timed out after {}s", limit.as_secs()),
            store::now_secs(),
        ),
    }
}

/// 发一封并把结果写回 Redis（结果流 + 确认 / 重试）
async fn process(sh: &Shared, mail: Mail) {
    let verdict = deliver(sh, &mail).await;
    let attempts = mail.attempt + 1;
    let res = async {
        match &verdict {
            Verdict::Sent { code } => {
                if mail.lane == Lane::Bulk {
                    sh.limiter.bulk_sent();
                }
                sh.store.mark_done(&mail.id).await?;
                sh.metrics.sent[lane_idx(mail.lane)].fetch_add(1, Ordering::Relaxed);
                sh.store
                    .push_result(&Outcome { mail_id: &mail.id, lane: mail.lane, status: "sent", class: "ok", code: Some(code.clone()), error: None, attempts, meta: &mail.meta })
                    .await?;
                debug!(id = %mail.id, lane = mail.lane.as_str(), "sent");
            }
            Verdict::Expired => {
                sh.metrics.expired.fetch_add(1, Ordering::Relaxed);
                sh.store
                    .push_result(&Outcome { mail_id: &mail.id, lane: mail.lane, status: "expired", class: "expired", code: None, error: None, attempts: mail.attempt, meta: &mail.meta })
                    .await?;
            }
            Verdict::Duplicate => {
                sh.metrics.duplicates.fetch_add(1, Ordering::Relaxed);
                info!(id = %mail.id, "已经发过，跳过");
            }
            Verdict::Deferred { after } => {
                sh.metrics.deferred.fetch_add(1, Ordering::Relaxed);
                info!(id = %mail.id, lane = mail.lane.as_str(), retry_in = after.as_secs(), "24 小时额度已满，延后发");
                sh.store.schedule_retry(&mail, *after, false).await?;
            }
            Verdict::Retry { class, after, error, .. } => {
                if *class == Failure::Throttled {
                    sh.limiter.throttled();
                    sh.metrics.throttle_events.fetch_add(1, Ordering::Relaxed);
                }
                sh.metrics.retried.fetch_add(1, Ordering::Relaxed);
                warn!(id = %mail.id, lane = mail.lane.as_str(), attempt = attempts, class = failure_class(*class), retry_in = after.as_secs(), error = %error, "投递失败，稍后重试");
                sh.store.schedule_retry(&mail, *after, true).await?;
            }
            Verdict::Failed { class, code, error } => {
                if *class == "throttled" {
                    sh.limiter.throttled();
                }
                sh.metrics.failed(class).fetch_add(1, Ordering::Relaxed);
                warn!(id = %mail.id, lane = mail.lane.as_str(), attempts, class, error = %error, "投递失败，不再重试");
                sh.store
                    .push_result(&Outcome { mail_id: &mail.id, lane: mail.lane, status: "failed", class, code: code.clone(), error: Some(error.clone()), attempts, meta: &mail.meta })
                    .await?;
            }
        }
        sh.store.ack(mail.lane, &mail.entry_id).await
    }
    .await;
    if let Err(e) = res {
        // 没确认成功：条目还在自己名下，重读待确认条目时会再领到；发成功的有 done 标记挡着
        sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
        error!(id = %mail.id, error = %e, "写回 Redis 失败");
    }
    sh.inflight.lock().unwrap().remove(&mail.entry_id);
}

pub struct Queues {
    pub tx: [async_channel::Sender<Mail>; 3],
    pub rx: [async_channel::Receiver<Mail>; 3],
}

impl Queues {
    pub fn new(cap: usize) -> Self {
        let (a, ar) = async_channel::bounded(cap);
        let (b, br) = async_channel::bounded(cap);
        let (c, cr) = async_channel::bounded(cap);
        Self {
            tx: [a, b, c],
            rx: [ar, br, cr],
        }
    }
}

/// 把读到的条目交给 worker；坏条目直接确认掉并回报 internal。返回最后一个条目 id；队列已关闭返回 Err
async fn dispatch(
    sh: &Shared,
    lane: Lane,
    tx: &async_channel::Sender<Mail>,
    entries: Vec<Result<Mail, crate::mail::Malformed>>,
) -> Result<Option<String>, ()> {
    let mut last = None;
    for entry in entries {
        match entry {
            Ok(mail) => {
                last = Some(mail.entry_id.clone());
                if !sh.inflight.lock().unwrap().insert(mail.entry_id.clone()) {
                    continue;
                }
                if tx.send(mail).await.is_err() {
                    return Err(());
                }
            }
            Err(bad) => {
                last = Some(bad.entry_id.clone());
                error!(lane = lane.as_str(), entry = %bad.entry_id, reason = %bad.reason, "通道里有坏条目，丢弃");
                sh.metrics.failed_internal.fetch_add(1, Ordering::Relaxed);
                if !bad.id.is_empty() {
                    let _ = sh
                        .store
                        .push_result(&Outcome {
                            mail_id: &bad.id,
                            lane,
                            status: "failed",
                            class: "internal",
                            code: None,
                            error: Some(bad.reason.clone()),
                            attempts: 0,
                            meta: "",
                        })
                        .await;
                }
                let _ = sh.store.ack(lane, &bad.entry_id).await;
            }
        }
    }
    Ok(last)
}

/// 一条通道的读取任务
pub async fn fetcher(
    sh: Arc<Shared>,
    lane: Lane,
    tx: async_channel::Sender<Mail>,
    mut stop: watch::Receiver<bool>,
) {
    let batch = (sh.cfg.connections * 2).clamp(4, 64);
    let mut conn = None;
    // 刚启动 / 出错重连后 / 每分钟：先把自己名下没确认的领回来（正在处理的会被跳过）
    let mut need_pending = true;
    let mut last_claim = tokio::time::Instant::now();
    'outer: loop {
        if *stop.borrow() {
            break;
        }
        if conn.is_none() {
            match sh.store.blocking_conn().await {
                Ok(c) => conn = Some(c),
                Err(e) => {
                    sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
                    warn!(lane = lane.as_str(), error = %e, "连 Redis 失败，2 秒后重试");
                    tokio::select! { _ = tokio::time::sleep(Duration::from_secs(2)) => {}, _ = stop.changed() => {} }
                    continue;
                }
            }
        }
        if last_claim.elapsed() >= Duration::from_secs(60) {
            last_claim = tokio::time::Instant::now();
            match sh.store.autoclaim(lane).await {
                Ok(n) if n > 0 => info!(lane = lane.as_str(), claimed = n, "领回闲置的待确认条目"),
                Ok(_) => {}
                Err(e) => warn!(lane = lane.as_str(), error = %e, "XAUTOCLAIM 失败"),
            }
            need_pending = true;
        }
        let c = conn.as_mut().unwrap();
        if need_pending {
            let mut cursor = "0-0".to_string();
            loop {
                let read = tokio::select! {
                    r = sh.store.read(c, lane, &cursor, batch) => r,
                    _ = stop.changed() => break 'outer,
                };
                match read {
                    Ok(entries) if entries.is_empty() => break,
                    Ok(entries) => match dispatch(&sh, lane, &tx, entries).await {
                        Ok(Some(last)) => cursor = last,
                        Ok(None) => break,
                        Err(()) => return,
                    },
                    Err(e) => {
                        sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
                        warn!(lane = lane.as_str(), error = %e, "读待确认条目失败，重连");
                        conn = None;
                        tokio::select! { _ = tokio::time::sleep(Duration::from_secs(1)) => {}, _ = stop.changed() => {} }
                        continue 'outer;
                    }
                }
            }
            need_pending = false;
        }
        let read = tokio::select! {
            r = sh.store.read(c, lane, ">", batch) => r,
            _ = stop.changed() => break,
        };
        match read {
            Ok(entries) => {
                if dispatch(&sh, lane, &tx, entries).await.is_err() {
                    return;
                }
            }
            Err(e) => {
                sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
                warn!(lane = lane.as_str(), error = %e, "读通道失败，重连");
                conn = None;
                need_pending = true;
                tokio::select! { _ = tokio::time::sleep(Duration::from_secs(1)) => {}, _ = stop.changed() => {} }
            }
        }
    }
    info!(lane = lane.as_str(), "读取任务停止");
}

/// 按优先级取一封。reserved 的 worker 不碰批量
async fn next(rx: &[async_channel::Receiver<Mail>; 3], bulk_ok: bool) -> Option<Mail> {
    // 先非阻塞地按顺序看一遍，保证有即时的时候绝不先拿交易 / 批量
    for (i, r) in rx.iter().enumerate() {
        if i == 2 && !bulk_ok {
            break;
        }
        if let Ok(m) = r.try_recv() {
            return Some(m);
        }
    }
    tokio::select! {
        biased;
        m = rx[0].recv() => m.ok(),
        m = rx[1].recv() => m.ok(),
        m = rx[2].recv(), if bulk_ok => m.ok(),
    }
}

pub async fn worker(
    sh: Arc<Shared>,
    idx: usize,
    rx: [async_channel::Receiver<Mail>; 3],
    mut stop: watch::Receiver<bool>,
) {
    let bulk_ok = idx >= sh.cfg.reserved;
    loop {
        if *stop.borrow() {
            break;
        }
        let mail = tokio::select! {
            m = next(&rx, bulk_ok) => m,
            _ = stop.changed() => None,
        };
        let Some(mail) = mail else { break };
        // 正在发的这封发完再停（停机时最多等一个 SMTP 超时）
        process(&sh, mail).await;
    }
    debug!(worker = idx, "worker 停止");
}

/// 到点的重试搬回通道
pub async fn mover(sh: Arc<Shared>, mut stop: watch::Receiver<bool>) {
    loop {
        match sh.store.move_due().await {
            Ok(n) if n > 0 => debug!(moved = n, "重试回到通道"),
            Ok(_) => {}
            Err(e) => {
                sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
                warn!(error = %e, "搬运重试失败");
            }
        }
        tokio::select! { _ = tokio::time::sleep(Duration::from_secs(1)) => {}, _ = stop.changed() => break }
    }
}

/// 心跳（面板据此判断投递服务在不在）+ 每 10 秒看一次 SMTP 配置
pub async fn housekeeping(sh: Arc<Shared>, mut stop: watch::Receiver<bool>) {
    let mut tick = 0u64;
    loop {
        if tick.is_multiple_of(2) {
            sh.reload_smtp().await;
        }
        // 每 10 分钟清掉 Redis 里滑出 24 小时窗口的分钟计数
        if tick.is_multiple_of(120) {
            let first_kept = store::now_secs().div_euclid(60) - daily::WINDOW_MINUTES + 1;
            if let Err(e) = sh.store.daily_prune(first_kept).await {
                warn!(error = %e, "清理 24 小时额度计数失败");
            }
        }
        let body = serde_json::json!({
            "consumer": sh.cfg.consumer,
            "version": env!("CARGO_PKG_VERSION"),
            "at": store::now_secs(),
            "smtp": sh.smtp_version().is_some(),
            "smtp_version": sh.smtp_version(),
            "inflight": sh.inflight_len(),
            "bulk_factor": sh.limiter.bulk_factor(),
            "daily_used": sh.daily.used(store::now_secs()),
            "daily_limit": sh.cfg.daily_limit,
        });
        // 没有 SMTP 配置时不发心跳：面板就会继续自己发，不会把信交给一个发不出去的服务
        if sh.smtp_version().is_some()
            && let Err(e) = sh.store.heartbeat(&body.to_string()).await
        {
            sh.metrics.redis_errors.fetch_add(1, Ordering::Relaxed);
            warn!(error = %e, "写心跳失败");
        }
        tick += 1;
        tokio::select! { _ = tokio::time::sleep(Duration::from_secs(5)) => {}, _ = stop.changed() => break }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn cfg() -> Config {
        Config {
            redis_url: String::new(),
            key_prefix: String::new(),
            consumer: "t".into(),
            connections: 4,
            reserved: 1,
            rate_per_minute: 0,
            bulk_share: 0.8,
            max_attempts: 3,
            backoff: vec![Duration::from_secs(3), Duration::from_secs(10)],
            throttle_max_attempts: 5,
            throttle_backoff: vec![Duration::from_secs(60), Duration::from_secs(300)],
            daily_limit: 0,
            daily_bulk_reserve: 0,
            max_message_bytes: 100,
            spool_dir: "/nonexistent".into(),
            http_addr: String::new(),
            smtp_timeout: Duration::from_secs(30),
        }
    }

    fn mail(lane: Lane, attempt: u32, not_after: i64) -> Mail {
        Mail {
            lane,
            entry_id: format!("{attempt}-0"),
            id: "m1".into(),
            from: "a@x.test".into(),
            to: "b@y.test".into(),
            eml: Some(b"x".to_vec()),
            eml_path: None,
            not_after,
            attempt,
            meta: String::new(),
        }
    }

    #[test]
    fn retries_until_max_attempts_then_gives_up() {
        let c = cfg();
        let v = after_failure(
            &c,
            &mail(Lane::Bulk, 0, 0),
            Failure::Transient,
            None,
            "e".into(),
            0,
        );
        assert!(matches!(v, Verdict::Retry { after, .. } if after == Duration::from_secs(3)));
        let v = after_failure(
            &c,
            &mail(Lane::Bulk, 1, 0),
            Failure::Transient,
            None,
            "e".into(),
            0,
        );
        assert!(matches!(v, Verdict::Retry { after, .. } if after == Duration::from_secs(10)));
        let v = after_failure(
            &c,
            &mail(Lane::Bulk, 2, 0),
            Failure::Transient,
            None,
            "e".into(),
            0,
        );
        assert!(matches!(
            v,
            Verdict::Failed {
                class: "transient",
                ..
            }
        ));
    }

    #[test]
    fn throttling_gets_more_and_longer_retries() {
        let c = cfg();
        // 普通临时失败第 3 次就放弃（max_attempts = 3），限流放宽到 5 次、间隔按限流的退避
        let v = after_failure(
            &c,
            &mail(Lane::Bulk, 2, 0),
            Failure::Throttled,
            Some("455".into()),
            "e".into(),
            0,
        );
        assert!(matches!(v, Verdict::Retry { after, .. } if after == Duration::from_secs(300)));
        let v = after_failure(
            &c,
            &mail(Lane::Bulk, 0, 0),
            Failure::Throttled,
            None,
            "e".into(),
            0,
        );
        assert!(matches!(v, Verdict::Retry { after, .. } if after == Duration::from_secs(60)));
        let v = after_failure(
            &c,
            &mail(Lane::Bulk, 4, 0),
            Failure::Throttled,
            None,
            "e".into(),
            0,
        );
        assert!(matches!(
            v,
            Verdict::Failed {
                class: "throttled",
                ..
            }
        ));
        // 验证码等不了一分钟的限流退避，过期前排不上就放弃
        assert!(matches!(
            after_failure(
                &c,
                &mail(Lane::Instant, 0, 30),
                Failure::Throttled,
                None,
                "e".into(),
                0
            ),
            Verdict::Failed { .. }
        ));
    }

    #[test]
    fn oversized_mail_is_refused_before_sending() {
        let c = cfg(); // max_message_bytes = 100
        assert!(too_large(&c, 100).is_none());
        assert!(matches!(
            too_large(&c, 101),
            Some(Verdict::Failed {
                class: "too_large",
                ..
            })
        ));
        let mut off = cfg();
        off.max_message_bytes = 0;
        assert!(too_large(&off, usize::MAX).is_none());
    }

    #[test]
    fn permanent_never_retries() {
        let v = after_failure(
            &cfg(),
            &mail(Lane::Instant, 0, 0),
            Failure::Permanent,
            Some("550".into()),
            "e".into(),
            0,
        );
        assert!(matches!(
            v,
            Verdict::Failed {
                class: "permanent",
                ..
            }
        ));
    }

    #[test]
    fn no_retry_past_deadline() {
        // 验证码 60 秒后过期，退避 3 秒还来得及，到第 58 秒失败就不排了
        let c = cfg();
        assert!(matches!(
            after_failure(
                &c,
                &mail(Lane::Instant, 0, 100),
                Failure::Transient,
                None,
                "e".into(),
                40
            ),
            Verdict::Retry { .. }
        ));
        assert!(matches!(
            after_failure(
                &c,
                &mail(Lane::Instant, 0, 100),
                Failure::Transient,
                None,
                "e".into(),
                98
            ),
            Verdict::Failed { .. }
        ));
    }

    #[tokio::test]
    async fn spool_path_must_be_a_bare_file_name() {
        let mut m = mail(Lane::Bulk, 0, 0);
        m.eml = None;
        m.eml_path = Some("../etc/passwd".into());
        assert!(
            load_eml(&cfg(), &m)
                .await
                .unwrap_err()
                .contains("bad eml_path")
        );
        m.eml_path = Some(".hidden".into());
        assert!(load_eml(&cfg(), &m).await.is_err());
    }

    #[tokio::test]
    async fn priority_order_and_reserved_workers() {
        let q = Queues::new(8);
        q.tx[2].send(mail(Lane::Bulk, 0, 0)).await.unwrap();
        q.tx[1].send(mail(Lane::Transactional, 0, 0)).await.unwrap();
        q.tx[0].send(mail(Lane::Instant, 0, 0)).await.unwrap();
        assert_eq!(next(&q.rx, true).await.unwrap().lane, Lane::Instant);
        assert_eq!(next(&q.rx, true).await.unwrap().lane, Lane::Transactional);
        // 预留 worker 只剩批量时不取
        let r = tokio::time::timeout(Duration::from_millis(50), next(&q.rx, false)).await;
        assert!(r.is_err(), "预留 worker 不能拿批量");
        assert_eq!(next(&q.rx, true).await.unwrap().lane, Lane::Bulk);
    }
}
