//! 发送限速：全局令牌桶（三条通道合计）+ 批量通道自己的桶（只能用全局的一部分），
//! 再加一个「服务商喊慢点」时的批量退让：收到限流回复（421 / 451 / 452 带限流字样，或 OCI 的 455）后批量暂停一阵、
//! 速率减半，之后慢慢回升。连着被限流，暂停时间翻倍（最长 10 分钟），批量发出去一封就重新从 30 秒算起。
//! 即时和交易只受全局桶约束，永远不被批量的退让拖住。

use crate::config::Lane;
use std::sync::Mutex;
use std::time::{Duration, Instant};

struct Bucket {
    per_sec: f64,
    capacity: f64,
    tokens: f64,
    last: Instant,
}

impl Bucket {
    fn new(per_minute: f64) -> Self {
        let per_sec = per_minute / 60.0;
        // 允许攒最多 1 秒的量（至少 1 个），避免空闲后一口气冲出去
        let capacity = per_sec.max(1.0);
        Self {
            per_sec,
            capacity,
            tokens: capacity,
            last: Instant::now(),
        }
    }

    fn refill(&mut self, now: Instant) {
        let elapsed = now.duration_since(self.last).as_secs_f64();
        self.tokens = (self.tokens + elapsed * self.per_sec).min(self.capacity);
        self.last = now;
    }

    /// 还差多久才有一个令牌（0 = 现在就有）
    fn wait(&self) -> Duration {
        if self.tokens >= 1.0 || self.per_sec <= 0.0 {
            return Duration::ZERO;
        }
        Duration::from_secs_f64((1.0 - self.tokens) / self.per_sec)
    }
}

struct State {
    global: Option<Bucket>,
    bulk: Option<Bucket>,
    bulk_base_per_minute: f64,
    /// 批量当前速率相对基础速率的比例（0.1–1.0），被限流时减半
    bulk_factor: f64,
    bulk_paused_until: Option<Instant>,
    last_recover: Instant,
    /// 连着被限流了几次（中间没有一封批量发成功）
    consecutive_throttles: u32,
}

pub struct Limiter {
    state: Mutex<State>,
}

/// 被服务商限流后批量暂停多久（连着被限流就翻倍，最长 MAX_THROTTLE_PAUSE）
const THROTTLE_PAUSE: Duration = Duration::from_secs(30);
const MAX_THROTTLE_PAUSE: Duration = Duration::from_secs(600);
/// 每过这么久批量速率回升 10 个百分点
const RECOVER_EVERY: Duration = Duration::from_secs(60);

impl Limiter {
    pub fn new(rate_per_minute: u32, bulk_share: f64) -> Self {
        let rate = rate_per_minute as f64;
        let bulk_rate = rate * bulk_share;
        Self {
            state: Mutex::new(State {
                global: (rate > 0.0).then(|| Bucket::new(rate)),
                bulk: (rate > 0.0).then(|| Bucket::new(bulk_rate)),
                bulk_base_per_minute: bulk_rate,
                bulk_factor: 1.0,
                bulk_paused_until: None,
                last_recover: Instant::now(),
                consecutive_throttles: 0,
            }),
        }
    }

    /// 拿到一个发送名额才返回
    pub async fn acquire(&self, lane: Lane) {
        loop {
            let wait = self.try_take(lane, Instant::now());
            if wait.is_zero() {
                return;
            }
            tokio::time::sleep(wait.min(Duration::from_secs(1))).await;
        }
    }

    /// 能拿就拿（返回 0），拿不到返回大概还要等多久
    fn try_take(&self, lane: Lane, now: Instant) -> Duration {
        let mut s = self.state.lock().unwrap();
        if lane == Lane::Bulk {
            if let Some(until) = s.bulk_paused_until {
                if now < until {
                    return until - now;
                }
                s.bulk_paused_until = None;
            }
            if s.bulk_factor < 1.0 && now.duration_since(s.last_recover) >= RECOVER_EVERY {
                s.bulk_factor = (s.bulk_factor + 0.1).min(1.0);
                s.last_recover = now;
                let per_minute = s.bulk_base_per_minute * s.bulk_factor;
                if let Some(b) = s.bulk.as_mut() {
                    b.per_sec = per_minute / 60.0;
                }
            }
        }
        if let Some(g) = s.global.as_mut() {
            g.refill(now);
        }
        if lane == Lane::Bulk
            && let Some(b) = s.bulk.as_mut()
        {
            b.refill(now);
        }
        let gw = s
            .global
            .as_ref()
            .map(|b| b.wait())
            .unwrap_or(Duration::ZERO);
        let bw = if lane == Lane::Bulk {
            s.bulk.as_ref().map(|b| b.wait()).unwrap_or(Duration::ZERO)
        } else {
            Duration::ZERO
        };
        let wait = gw.max(bw);
        if !wait.is_zero() {
            return wait;
        }
        if let Some(g) = s.global.as_mut() {
            g.tokens -= 1.0;
        }
        if lane == Lane::Bulk
            && let Some(b) = s.bulk.as_mut()
        {
            b.tokens -= 1.0;
        }
        Duration::ZERO
    }

    /// 服务商回了限流类错误：批量暂停（30 秒起，连着被限流就翻倍）、速率减半（不限速时只暂停）
    pub fn throttled(&self) {
        let now = Instant::now();
        let mut s = self.state.lock().unwrap();
        let pause = THROTTLE_PAUSE
            .saturating_mul(1u32 << s.consecutive_throttles.min(5))
            .min(MAX_THROTTLE_PAUSE);
        s.consecutive_throttles = s.consecutive_throttles.saturating_add(1);
        s.bulk_paused_until = Some(now + pause);
        s.bulk_factor = (s.bulk_factor / 2.0).max(0.1);
        s.last_recover = now;
        let per_minute = s.bulk_base_per_minute * s.bulk_factor;
        if let Some(b) = s.bulk.as_mut() {
            b.per_sec = per_minute / 60.0;
        }
    }

    /// 批量发成功了一封：下次被限流重新从 30 秒暂停算起
    pub fn bulk_sent(&self) {
        self.state.lock().unwrap().consecutive_throttles = 0;
    }

    pub fn bulk_factor(&self) -> f64 {
        self.state.lock().unwrap().bulk_factor
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unlimited_never_waits() {
        let l = Limiter::new(0, 0.8);
        let now = Instant::now();
        for _ in 0..1000 {
            assert!(l.try_take(Lane::Bulk, now).is_zero());
        }
    }

    #[test]
    fn bulk_cannot_starve_instant() {
        // 600 封 / 分钟 = 每秒 10 封，批量最多占一半 = 每秒 5 封
        let l = Limiter::new(600, 0.5);
        let now = Instant::now();
        let mut bulk = 0;
        while l.try_take(Lane::Bulk, now).is_zero() {
            bulk += 1;
        }
        assert_eq!(bulk, 5, "批量用完自己的桶就停");
        // 全局桶里还剩一半给即时
        let mut instant = 0;
        while l.try_take(Lane::Instant, now).is_zero() {
            instant += 1;
        }
        assert_eq!(instant, 5);
    }

    #[test]
    fn throttle_pauses_bulk_only() {
        let l = Limiter::new(0, 0.8);
        l.throttled();
        let now = Instant::now();
        assert!(!l.try_take(Lane::Bulk, now).is_zero(), "批量暂停");
        assert!(l.try_take(Lane::Instant, now).is_zero(), "即时不受影响");
        assert!(
            l.try_take(Lane::Transactional, now).is_zero(),
            "交易不受影响"
        );
        assert!(
            l.try_take(Lane::Bulk, now + THROTTLE_PAUSE + Duration::from_millis(1))
                .is_zero(),
            "30 秒后恢复"
        );
        assert!((l.bulk_factor() - 0.5).abs() < 1e-9);
    }

    #[test]
    fn repeated_throttling_backs_off_longer() {
        let l = Limiter::new(0, 0.8);
        l.throttled();
        l.throttled();
        let now = Instant::now();
        assert!(
            !l.try_take(Lane::Bulk, now + THROTTLE_PAUSE + Duration::from_secs(1))
                .is_zero(),
            "第二次连着被限流暂停 60 秒"
        );
        assert!(
            l.try_take(
                Lane::Bulk,
                now + THROTTLE_PAUSE * 2 + Duration::from_secs(1)
            )
            .is_zero()
        );
        for _ in 0..10 {
            l.throttled();
        }
        let now = Instant::now();
        assert!(
            l.try_take(
                Lane::Bulk,
                now + MAX_THROTTLE_PAUSE + Duration::from_secs(1)
            )
            .is_zero(),
            "最长暂停 10 分钟"
        );
        l.bulk_sent();
        l.throttled();
        let now = Instant::now();
        assert!(
            l.try_take(Lane::Bulk, now + THROTTLE_PAUSE + Duration::from_secs(1))
                .is_zero(),
            "发成功一封后重新从 30 秒算"
        );
    }
}
