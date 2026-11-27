//! /healthz（容器健康检查用）和 /metrics（Prometheus 文本格式）

use crate::config::Lane;
use crate::relay::Shared;
use axum::Router;
use axum::extract::State;
use axum::http::StatusCode;
use axum::routing::get;
use std::fmt::Write;
use std::sync::Arc;
use std::sync::atomic::Ordering;

pub fn router(sh: Arc<Shared>) -> Router {
    Router::new()
        .route("/healthz", get(healthz))
        .route("/metrics", get(metrics))
        .with_state(sh)
}

async fn healthz(State(sh): State<Arc<Shared>>) -> (StatusCode, String) {
    if !sh.store.ping().await {
        return (
            StatusCode::SERVICE_UNAVAILABLE,
            "redis unreachable\n".into(),
        );
    }
    match sh.smtp_host() {
        Some(host) => (StatusCode::OK, format!("ok smtp={host}\n")),
        // 没配置 SMTP 不算挂：面板看不到心跳会自己发，这里只是闲着
        None => (StatusCode::OK, "ok (no smtp config yet, idle)\n".into()),
    }
}

async fn metrics(State(sh): State<Arc<Shared>>) -> String {
    let m = &sh.metrics;
    let mut out = String::new();
    let _ = writeln!(out, "# TYPE mail_relay_sent_total counter");
    for (i, lane) in Lane::ALL.into_iter().enumerate() {
        let _ = writeln!(
            out,
            "mail_relay_sent_total{{lane=\"{}\"}} {}",
            lane.as_str(),
            m.sent[i].load(Ordering::Relaxed)
        );
    }
    let _ = writeln!(out, "# TYPE mail_relay_failed_total counter");
    for (class, v) in [
        ("permanent", &m.failed_permanent),
        ("transient", &m.failed_transient),
        ("config", &m.failed_config),
        ("throttled", &m.failed_throttled),
        ("internal", &m.failed_internal),
    ] {
        let _ = writeln!(
            out,
            "mail_relay_failed_total{{class=\"{class}\"}} {}",
            v.load(Ordering::Relaxed)
        );
    }
    for (name, v) in [
        ("mail_relay_expired_total", &m.expired),
        ("mail_relay_retried_total", &m.retried),
        ("mail_relay_duplicates_total", &m.duplicates),
        ("mail_relay_throttle_events_total", &m.throttle_events),
        ("mail_relay_redis_errors_total", &m.redis_errors),
    ] {
        let _ = writeln!(
            out,
            "# TYPE {name} counter\n{name} {}",
            v.load(Ordering::Relaxed)
        );
    }
    let _ = writeln!(
        out,
        "# TYPE mail_relay_inflight gauge\nmail_relay_inflight {}",
        sh.inflight_len()
    );
    let _ = writeln!(
        out,
        "# TYPE mail_relay_bulk_rate_factor gauge\nmail_relay_bulk_rate_factor {}",
        sh.limiter.bulk_factor()
    );
    let _ = writeln!(
        out,
        "# TYPE mail_relay_smtp_configured gauge\nmail_relay_smtp_configured {}",
        u8::from(sh.smtp_version().is_some())
    );
    if let Ok(d) = sh.store.depths().await {
        let _ = writeln!(out, "# TYPE mail_relay_queue_depth gauge");
        for (i, lane) in Lane::ALL.into_iter().enumerate() {
            let _ = writeln!(
                out,
                "mail_relay_queue_depth{{lane=\"{}\"}} {}",
                lane.as_str(),
                d.lanes[i]
            );
        }
        let _ = writeln!(
            out,
            "# TYPE mail_relay_delayed gauge\nmail_relay_delayed {}",
            d.delayed
        );
    }
    out
}
