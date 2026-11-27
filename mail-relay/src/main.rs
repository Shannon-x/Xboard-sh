//! xboard 邮件投递服务：从 Redis Streams 取面板排好的信，用一个 SMTP 连接池并发发出去，结果写回 Redis。
//! 设计与上线方式见仓库 docs/mail-relay.md。

mod config;
mod daily;
mod http;
mod limiter;
mod mail;
mod relay;
mod smtp;
mod store;

use config::{Config, Lane};
use std::time::Duration;
use tokio::sync::watch;
use tracing::{info, warn};

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let json = std::env::var("RELAY_LOG_FORMAT")
        .map(|v| v == "json")
        .unwrap_or(false);
    let filter =
        tracing_subscriber::EnvFilter::try_from_env("RELAY_LOG").unwrap_or_else(|_| "info".into());
    if json {
        tracing_subscriber::fmt()
            .with_env_filter(filter)
            .json()
            .init();
    } else {
        tracing_subscriber::fmt().with_env_filter(filter).init();
    }

    let cfg = Config::from_env()?;
    info!(
        consumer = %cfg.consumer,
        connections = cfg.connections,
        reserved = cfg.reserved,
        rate_per_minute = cfg.rate_per_minute,
        prefix = %cfg.key_prefix,
        "mail-relay {} 启动",
        env!("CARGO_PKG_VERSION")
    );

    let store = store::Store::connect(&cfg).await?;
    store.ensure_groups().await?;
    let sh = relay::Shared::new(cfg.clone(), store);
    match sh.store.daily_load().await {
        Ok(saved) => sh.daily.load(&saved, store::now_secs()),
        Err(e) => warn!(error = %e, "读 24 小时额度计数失败，从 0 开始算"),
    }
    sh.reload_smtp().await;

    let (stop_tx, stop_rx) = watch::channel(false);
    let queues = relay::Queues::new((cfg.connections * 2).max(8));
    let mut fetchers = Vec::new();
    for (i, lane) in Lane::ALL.into_iter().enumerate() {
        fetchers.push(tokio::spawn(relay::fetcher(
            sh.clone(),
            lane,
            queues.tx[i].clone(),
            stop_rx.clone(),
        )));
    }
    let mut workers = Vec::new();
    for idx in 0..cfg.connections {
        workers.push(tokio::spawn(relay::worker(
            sh.clone(),
            idx,
            queues.rx.clone(),
            stop_rx.clone(),
        )));
    }
    let bg = vec![
        tokio::spawn(relay::mover(sh.clone(), stop_rx.clone())),
        tokio::spawn(relay::housekeeping(sh.clone(), stop_rx.clone())),
    ];

    let listener = tokio::net::TcpListener::bind(&cfg.http_addr).await?;
    info!(addr = %cfg.http_addr, "HTTP /healthz /metrics");
    let mut http_stop = stop_rx.clone();
    let server = tokio::spawn(async move {
        let _ = axum::serve(listener, http::router(sh.clone()))
            .with_graceful_shutdown(async move {
                let _ = http_stop.changed().await;
            })
            .await;
    });

    shutdown_signal().await;
    info!("收到停止信号：不再取新信，正在发的发完就退出");
    let _ = stop_tx.send(true);
    drop(queues);
    let grace = cfg.smtp_timeout * 3 + Duration::from_secs(5);
    let all = async {
        for h in fetchers.into_iter().chain(workers).chain(bg) {
            let _ = h.await;
        }
        let _ = server.await;
    };
    if tokio::time::timeout(grace, all).await.is_err() {
        warn!(
            "等了 {} 秒还有信没发完，直接退出（没确认的下次启动会重新领回来）",
            grace.as_secs()
        );
    }
    Ok(())
}

async fn shutdown_signal() {
    #[cfg(unix)]
    {
        let mut term = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
            .expect("SIGTERM handler");
        tokio::select! {
            _ = tokio::signal::ctrl_c() => {},
            _ = term.recv() => {},
        }
    }
    #[cfg(not(unix))]
    {
        let _ = tokio::signal::ctrl_c().await;
    }
}
