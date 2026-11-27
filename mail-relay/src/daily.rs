//! 滚动 24 小时发送额度（OCI 的 max-emails-day：按收件人计、滚动窗口、不是零点清零）。
//! 按分钟分桶记在内存里，同时写进 Redis 的一个 hash（字段 = 分钟数），重启时读回来，额度不会因为重启归零。
//! 领名额时就计数（发失败也算，只有被限流的那次还回去），宁可少发几封也不要撞到服务商的上限。

use crate::config::{Config, Lane};
use std::collections::{BTreeMap, HashMap};
use std::sync::Mutex;
use std::time::Duration;

pub const WINDOW_MINUTES: i64 = 24 * 60;

pub struct Daily {
    /// 分钟数（unix 秒 / 60）→ 这一分钟领出去的名额
    buckets: Mutex<BTreeMap<i64, u32>>,
}

impl Default for Daily {
    fn default() -> Self {
        Self::new()
    }
}

impl Daily {
    pub fn new() -> Self {
        Self {
            buckets: Mutex::new(BTreeMap::new()),
        }
    }

    /// 启动时把 Redis 里的计数读回来
    pub fn load(&self, saved: &HashMap<String, String>, now_secs: i64) {
        let mut b = self.buckets.lock().unwrap();
        for (k, v) in saved {
            if let (Ok(m), Ok(n)) = (k.parse::<i64>(), v.parse::<u32>()) {
                *b.entry(m).or_default() += n;
            }
        }
        prune(&mut b, now_secs);
    }

    /// 最近 24 小时用了多少
    pub fn used(&self, now_secs: i64) -> u64 {
        let mut b = self.buckets.lock().unwrap();
        prune(&mut b, now_secs);
        b.values().map(|&n| n as u64).sum()
    }

    /// 领一个名额：领到返回这一分钟的分钟数（要写回 Redis），领不到返回大概要等多久才有空位
    pub fn take(&self, cfg: &Config, lane: Lane, now_secs: i64) -> Result<i64, Duration> {
        let cap = cfg.daily_cap(lane);
        let minute = now_secs.div_euclid(60);
        let mut b = self.buckets.lock().unwrap();
        prune(&mut b, now_secs);
        if cfg.daily_limit > 0 {
            let used: u64 = b.values().map(|&n| n as u64).sum();
            if used >= cap as u64 {
                // 最老的那一分钟滑出窗口时就会空出名额；至少等 1 分钟，免得空转
                let oldest = b.keys().next().copied().unwrap_or(minute);
                let free_at = (oldest + WINDOW_MINUTES + 1) * 60;
                let wait = (free_at - now_secs).max(60) as u64;
                return Err(Duration::from_secs(wait));
            }
        }
        *b.entry(minute).or_default() += 1;
        Ok(minute)
    }
}

impl Daily {
    /// 领了名额却被服务商限流（没收下）：还回去
    pub fn give_back(&self, minute: i64) {
        let mut b = self.buckets.lock().unwrap();
        if let Some(n) = b.get_mut(&minute) {
            *n = n.saturating_sub(1);
            if *n == 0 {
                b.remove(&minute);
            }
        }
    }
}

fn prune(b: &mut BTreeMap<i64, u32>, now_secs: i64) {
    let first_kept = now_secs.div_euclid(60) - WINDOW_MINUTES + 1;
    *b = b.split_off(&first_kept);
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    fn cfg(limit: u32, reserve: u32) -> Config {
        Config {
            redis_url: String::new(),
            key_prefix: String::new(),
            consumer: "t".into(),
            connections: 4,
            reserved: 1,
            rate_per_minute: 0,
            bulk_share: 0.8,
            max_attempts: 3,
            backoff: vec![Duration::from_secs(3)],
            throttle_max_attempts: 5,
            throttle_backoff: vec![Duration::from_secs(60)],
            daily_limit: limit,
            daily_bulk_reserve: reserve,
            max_message_bytes: 0,
            spool_dir: String::new(),
            http_addr: String::new(),
            smtp_timeout: Duration::from_secs(30),
        }
    }

    #[test]
    fn bulk_stops_first_and_codes_still_go_out() {
        let c = cfg(10, 3);
        let d = Daily::new();
        let t = 1_000_000;
        for _ in 0..7 {
            assert!(d.take(&c, Lane::Bulk, t).is_ok());
        }
        assert!(d.take(&c, Lane::Bulk, t).is_err(), "批量用到 10 - 3 就停");
        for _ in 0..3 {
            assert!(d.take(&c, Lane::Instant, t).is_ok(), "留给验证码的 3 封");
        }
        assert!(d.take(&c, Lane::Instant, t).is_err(), "总额度用完");
    }

    #[test]
    fn rolling_window_frees_old_minutes() {
        let c = cfg(2, 0);
        let d = Daily::new();
        let t = 6_000_000;
        d.take(&c, Lane::Bulk, t).unwrap();
        d.take(&c, Lane::Bulk, t + 3600).unwrap();
        let wait = d.take(&c, Lane::Bulk, t + 7200).unwrap_err();
        // 第一封在 t 发出，24 小时后滑出窗口
        assert!(
            wait >= Duration::from_secs(22 * 3600) && wait <= Duration::from_secs(22 * 3600 + 120)
        );
        assert!(d.take(&c, Lane::Bulk, t + 86_400 + 60).is_ok());
        assert_eq!(d.used(t + 86_400 + 60), 2);
    }

    #[test]
    fn restart_keeps_the_count() {
        let c = cfg(3, 0);
        let d = Daily::new();
        let t = 9_000_000;
        let mut saved = HashMap::new();
        saved.insert(((t - 600) / 60).to_string(), "2".to_string());
        saved.insert(((t - 90_000) / 60).to_string(), "50".to_string()); // 25 小时前的，不算
        saved.insert("garbage".to_string(), "x".to_string());
        d.load(&saved, t);
        assert_eq!(d.used(t), 2);
        assert!(d.take(&c, Lane::Bulk, t).is_ok());
        assert!(d.take(&c, Lane::Bulk, t).is_err());
    }

    #[test]
    fn throttled_attempts_give_the_slot_back() {
        let c = cfg(1, 0);
        let d = Daily::new();
        let m = d.take(&c, Lane::Instant, 100).unwrap();
        assert!(d.take(&c, Lane::Instant, 100).is_err());
        d.give_back(m);
        assert!(d.take(&c, Lane::Instant, 100).is_ok());
    }

    #[test]
    fn zero_means_unlimited() {
        let c = cfg(0, 0);
        let d = Daily::new();
        for _ in 0..1000 {
            assert!(d.take(&c, Lane::Bulk, 1).is_ok());
        }
    }
}
