//! Redis 上的全部读写。key 名都由 Config::key 拼出，和面板的 MailRelay 一致。
//!
//!   stream:<lane>  三条通道（消费者组 relay，只有一个消费者）
//!   delayed        待重试的信：zset，score = 何时重新入队，member = "<lane>|<retry key>"
//!   retry:<id>:<n> 待重试那封信的字段（hash），到点后搬回通道
//!   done:<id>      发成功的标记（7 天），重启 / 重复入队时不再发第二遍
//!   results        结果流，面板 mail:relay-results 读它记账、写回执状态
//!   config         SMTP 账号（hash，面板推）
//!   heartbeat      心跳（30 秒过期），面板看它决定要不要把信交给这里

use crate::config::{Config, GROUP, Lane};
use crate::mail::{self, Mail};
use redis::aio::{ConnectionManager, ConnectionManagerConfig, MultiplexedConnection};
use redis::{AsyncConnectionConfig, Client, Value};
use std::collections::HashMap;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

pub const DONE_TTL_SECS: u64 = 7 * 24 * 3600;
pub const RESULTS_MAXLEN: usize = 50_000;
/// 阻塞读一次最多等多久（毫秒）
pub const BLOCK_MS: u64 = 5_000;

pub fn now_secs() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

#[derive(Clone)]
pub struct Store {
    client: Client,
    conn: ConnectionManager,
    cfg: Config,
}

/// 一条结果，写进 results 流
pub struct Outcome<'a> {
    pub mail_id: &'a str,
    pub lane: Lane,
    /// sent / failed / expired
    pub status: &'a str,
    /// ok / permanent / transient / config / throttled / internal / expired
    pub class: &'a str,
    pub code: Option<String>,
    pub error: Option<String>,
    pub attempts: u32,
    pub meta: &'a str,
}

impl Store {
    pub async fn connect(cfg: &Config) -> anyhow::Result<Self> {
        let client = Client::open(cfg.redis_url.as_str())?;
        let mc = ConnectionManagerConfig::new()
            .set_response_timeout(Some(Duration::from_secs(10)))
            .set_connection_timeout(Some(Duration::from_secs(5)));
        let conn = ConnectionManager::new_with_config(client.clone(), mc).await?;
        Ok(Self {
            client,
            conn,
            cfg: cfg.clone(),
        })
    }

    /// 每条通道一条专用连接做阻塞读（阻塞命令会卡住共用连接上的其它命令）
    pub async fn blocking_conn(&self) -> redis::RedisResult<MultiplexedConnection> {
        let c = AsyncConnectionConfig::new()
            .set_response_timeout(Some(Duration::from_millis(BLOCK_MS + 10_000)))
            .set_connection_timeout(Some(Duration::from_secs(5)));
        self.client
            .get_multiplexed_async_connection_with_config(&c)
            .await
    }

    pub async fn ping(&self) -> bool {
        let mut c = self.conn.clone();
        redis::cmd("PING")
            .query_async::<String>(&mut c)
            .await
            .is_ok()
    }

    pub async fn ensure_groups(&self) -> anyhow::Result<()> {
        let mut c = self.conn.clone();
        for lane in Lane::ALL {
            let r: redis::RedisResult<Value> = redis::cmd("XGROUP")
                .arg("CREATE")
                .arg(lane.stream_key(&self.cfg))
                .arg(GROUP)
                .arg("0")
                .arg("MKSTREAM")
                .query_async(&mut c)
                .await;
            if let Err(e) = r
                && !e.to_string().contains("BUSYGROUP")
            {
                return Err(e.into());
            }
        }
        Ok(())
    }

    /// 读一批。start = "0" 读自己名下没确认的，">" 读新的（会阻塞 BLOCK_MS）
    pub async fn read(
        &self,
        conn: &mut MultiplexedConnection,
        lane: Lane,
        start: &str,
        count: usize,
    ) -> redis::RedisResult<Vec<Result<Mail, mail::Malformed>>> {
        let mut cmd = redis::cmd("XREADGROUP");
        cmd.arg("GROUP")
            .arg(GROUP)
            .arg(&self.cfg.consumer)
            .arg("COUNT")
            .arg(count);
        if start == ">" {
            cmd.arg("BLOCK").arg(BLOCK_MS);
        }
        cmd.arg("STREAMS")
            .arg(lane.stream_key(&self.cfg))
            .arg(start);
        let v: Value = cmd.query_async(conn).await?;
        Ok(mail::parse_xread(&v)
            .into_iter()
            .map(|(eid, f)| Mail::from_entry(lane, eid, &f))
            .collect())
    }

    /// 别的消费者名下闲置超过 5 分钟的信领过来（换过 RELAY_CONSUMER、或两个实例交接时）
    pub async fn autoclaim(&self, lane: Lane) -> redis::RedisResult<usize> {
        let mut c = self.conn.clone();
        let v: Value = redis::cmd("XAUTOCLAIM")
            .arg(lane.stream_key(&self.cfg))
            .arg(GROUP)
            .arg(&self.cfg.consumer)
            .arg(300_000)
            .arg("0-0")
            .arg("COUNT")
            .arg(200)
            .arg("JUSTID")
            .query_async(&mut c)
            .await?;
        Ok(match v {
            Value::Array(parts) if parts.len() >= 2 => match &parts[1] {
                Value::Array(ids) => ids.len(),
                _ => 0,
            },
            _ => 0,
        })
    }

    /// 确认并删掉条目（通道里存着整封信，留着占内存）
    pub async fn ack(&self, lane: Lane, entry_id: &str) -> redis::RedisResult<()> {
        let mut c = self.conn.clone();
        let key = lane.stream_key(&self.cfg);
        redis::pipe()
            .cmd("XACK")
            .arg(&key)
            .arg(GROUP)
            .arg(entry_id)
            .ignore()
            .cmd("XDEL")
            .arg(&key)
            .arg(entry_id)
            .ignore()
            .query_async::<()>(&mut c)
            .await
    }

    pub async fn is_done(&self, mail_id: &str) -> redis::RedisResult<bool> {
        let mut c = self.conn.clone();
        redis::cmd("EXISTS")
            .arg(self.cfg.key(&format!("done:{mail_id}")))
            .query_async::<i64>(&mut c)
            .await
            .map(|n| n > 0)
    }

    pub async fn mark_done(&self, mail_id: &str) -> redis::RedisResult<()> {
        let mut c = self.conn.clone();
        redis::cmd("SET")
            .arg(self.cfg.key(&format!("done:{mail_id}")))
            .arg(now_secs())
            .arg("EX")
            .arg(DONE_TTL_SECS)
            .query_async::<()>(&mut c)
            .await
    }

    pub async fn push_result(&self, o: &Outcome<'_>) -> redis::RedisResult<()> {
        let mut c = self.conn.clone();
        let mut cmd = redis::cmd("XADD");
        cmd.arg(self.cfg.key("results"))
            .arg("MAXLEN")
            .arg("~")
            .arg(RESULTS_MAXLEN)
            .arg("*");
        cmd.arg("id")
            .arg(o.mail_id)
            .arg("lane")
            .arg(o.lane.as_str())
            .arg("status")
            .arg(o.status)
            .arg("class")
            .arg(o.class);
        cmd.arg("attempts")
            .arg(o.attempts)
            .arg("meta")
            .arg(o.meta)
            .arg("at")
            .arg(now_secs());
        if let Some(code) = &o.code {
            cmd.arg("code").arg(code);
        }
        if let Some(err) = &o.error {
            cmd.arg("error").arg(truncate(err, 500));
        }
        cmd.query_async::<()>(&mut c).await
    }

    /// 放进重试队列：先存字段、再挂到 delayed，调用方随后才确认原条目（中间崩了最多多发一次重试，done 标记会挡住重复投递）
    pub async fn schedule_retry(&self, mail: &Mail, after: Duration) -> redis::RedisResult<()> {
        let mut c = self.conn.clone();
        let rkey = self
            .cfg
            .key(&format!("retry:{}:{}", mail.id, mail.attempt + 1));
        let mut hset = redis::cmd("HSET");
        hset.arg(&rkey);
        for (k, v) in mail.retry_fields() {
            hset.arg(k).arg(v);
        }
        let at = now_secs() + after.as_secs().max(1) as i64;
        redis::pipe()
            .add_command(hset)
            .ignore()
            .cmd("EXPIRE")
            .arg(&rkey)
            .arg(DONE_TTL_SECS)
            .ignore()
            .cmd("ZADD")
            .arg(self.cfg.key("delayed"))
            .arg(at)
            .arg(format!("{}|{}", mail.lane.as_str(), rkey))
            .ignore()
            .query_async::<()>(&mut c)
            .await
    }

    /// 到点的重试搬回通道，返回搬了几封
    pub async fn move_due(&self) -> redis::RedisResult<usize> {
        let mut c = self.conn.clone();
        let delayed = self.cfg.key("delayed");
        let due: Vec<String> = redis::cmd("ZRANGEBYSCORE")
            .arg(&delayed)
            .arg("-inf")
            .arg(now_secs())
            .arg("LIMIT")
            .arg(0)
            .arg(200)
            .query_async(&mut c)
            .await?;
        let mut moved = 0;
        for member in due {
            let Some((lane, rkey)) = member.split_once('|') else {
                redis::cmd("ZREM")
                    .arg(&delayed)
                    .arg(&member)
                    .query_async::<()>(&mut c)
                    .await?;
                continue;
            };
            let Some(lane) = Lane::parse(lane) else {
                redis::cmd("ZREM")
                    .arg(&delayed)
                    .arg(&member)
                    .query_async::<()>(&mut c)
                    .await?;
                continue;
            };
            let fields: Vec<(String, Vec<u8>)> = redis::cmd("HGETALL")
                .arg(rkey)
                .query_async::<HashMap<String, Vec<u8>>>(&mut c)
                .await?
                .into_iter()
                .collect();
            if !fields.is_empty() {
                let mut x = redis::cmd("XADD");
                x.arg(lane.stream_key(&self.cfg)).arg("*");
                for (k, v) in &fields {
                    x.arg(k).arg(v.as_slice());
                }
                x.query_async::<()>(&mut c).await?;
                moved += 1;
            }
            redis::pipe()
                .cmd("ZREM")
                .arg(&delayed)
                .arg(&member)
                .ignore()
                .cmd("DEL")
                .arg(rkey)
                .ignore()
                .query_async::<()>(&mut c)
                .await?;
        }
        Ok(moved)
    }

    pub async fn heartbeat(&self, body: &str) -> redis::RedisResult<()> {
        let mut c = self.conn.clone();
        redis::cmd("SET")
            .arg(self.cfg.key("heartbeat"))
            .arg(body)
            .arg("EX")
            .arg(30)
            .query_async::<()>(&mut c)
            .await
    }

    pub async fn smtp_config(&self) -> redis::RedisResult<HashMap<String, String>> {
        let mut c = self.conn.clone();
        redis::cmd("HGETALL")
            .arg(self.cfg.key("config"))
            .query_async(&mut c)
            .await
    }

    /// 各通道还没处理的条数（已确认的会被 XDEL，剩下的就是积压 + 正在发的）和待重试数
    pub async fn depths(&self) -> redis::RedisResult<Depths> {
        let mut c = self.conn.clone();
        let mut p = redis::pipe();
        for lane in Lane::ALL {
            p.cmd("XLEN").arg(lane.stream_key(&self.cfg));
        }
        p.cmd("ZCARD").arg(self.cfg.key("delayed"));
        let n: Vec<i64> = p.query_async(&mut c).await?;
        Ok(Depths {
            lanes: [n[0], n[1], n[2]],
            delayed: n[3],
        })
    }
}

pub struct Depths {
    /// 顺序同 Lane::ALL
    pub lanes: [i64; 3],
    pub delayed: i64,
}

pub fn truncate(s: &str, max: usize) -> String {
    if s.len() <= max {
        return s.to_string();
    }
    let mut end = max;
    while !s.is_char_boundary(end) {
        end -= 1;
    }
    format!("{}…", &s[..end])
}
