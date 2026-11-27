//! 通道里的一封信：字段约定与面板 App\Services\Mail\MailRelay 一致（改一边要改另一边）。
//!
//! 通道条目（Redis Stream `mail:relay:stream:<lane>`）字段：
//!   id         面板生成的唯一 id（也写进邮件的 Message-ID），幂等去重用
//!   from       信封发件人（MAIL FROM）
//!   to         信封收件人（RCPT TO），一封一个
//!   eml        完整 MIME 原文（小邮件直接放这里）
//!   eml_path   或者：spool 目录里的文件名（带 PDF 的大邮件）
//!   not_after  过了这个时间戳还没发出去就不发（0 = 不过期）
//!   attempt    之前已经失败了几次（面板写 0）
//!   meta       面板自己的 JSON，原样带回结果流

use crate::config::Lane;
use redis::Value;

#[derive(Clone, Debug)]
pub struct Mail {
    pub lane: Lane,
    /// 在 Redis Stream 里的条目 id（XACK 用），不是面板的 id
    pub entry_id: String,
    pub id: String,
    pub from: String,
    pub to: String,
    pub eml: Option<Vec<u8>>,
    pub eml_path: Option<String>,
    pub not_after: i64,
    pub attempt: u32,
    pub meta: String,
}

fn text(v: &Value) -> Option<String> {
    match v {
        Value::BulkString(b) => Some(String::from_utf8_lossy(b).into_owned()),
        Value::SimpleString(s) => Some(s.clone()),
        Value::Int(i) => Some(i.to_string()),
        Value::VerbatimString { text, .. } => Some(text.clone()),
        _ => None,
    }
}

fn bytes(v: &Value) -> Option<Vec<u8>> {
    match v {
        Value::BulkString(b) => Some(b.clone()),
        Value::SimpleString(s) => Some(s.clone().into_bytes()),
        _ => None,
    }
}

/// 字段列表（[f1, v1, f2, v2…] 或 RESP3 的 map）→ (字段名, 原始值)
pub fn field_pairs(v: &Value) -> Vec<(String, Value)> {
    match v {
        Value::Array(items) => items
            .chunks(2)
            .filter_map(|c| {
                if c.len() == 2 {
                    text(&c[0]).map(|k| (k, c[1].clone()))
                } else {
                    None
                }
            })
            .collect(),
        Value::Map(pairs) => pairs
            .iter()
            .filter_map(|(k, v)| text(k).map(|k| (k, v.clone())))
            .collect(),
        _ => Vec::new(),
    }
}

/// 解析失败的条目：入口 id + 原因，由调用方确认掉并回报 internal
pub struct Malformed {
    pub entry_id: String,
    pub id: String,
    pub reason: String,
}

impl Mail {
    pub fn from_entry(
        lane: Lane,
        entry_id: String,
        fields: &[(String, Value)],
    ) -> Result<Mail, Malformed> {
        let get = |name: &str| fields.iter().find(|(k, _)| k == name).map(|(_, v)| v);
        let id = get("id").and_then(text).unwrap_or_default();
        let bad = |reason: &str| Malformed {
            entry_id: entry_id.clone(),
            id: id.clone(),
            reason: reason.to_string(),
        };
        if fields.is_empty() {
            return Err(bad("entry has no fields (trimmed?)"));
        }
        if id.is_empty() {
            return Err(bad("missing id"));
        }
        let from = get("from").and_then(text).unwrap_or_default();
        let to = get("to").and_then(text).unwrap_or_default();
        if from.is_empty() || to.is_empty() {
            return Err(bad("missing from / to"));
        }
        let eml = get("eml").and_then(bytes).filter(|b| !b.is_empty());
        let eml_path = get("eml_path").and_then(text).filter(|p| !p.is_empty());
        if eml.is_none() && eml_path.is_none() {
            return Err(bad("missing eml / eml_path"));
        }
        Ok(Mail {
            lane,
            entry_id,
            id,
            from,
            to,
            eml,
            eml_path,
            not_after: get("not_after")
                .and_then(text)
                .and_then(|s| s.parse().ok())
                .unwrap_or(0),
            attempt: get("attempt")
                .and_then(text)
                .and_then(|s| s.parse().ok())
                .unwrap_or(0),
            meta: get("meta").and_then(text).unwrap_or_default(),
        })
    }

    /// 重新入队用的字段（attempt 已加 1）
    /// 排进延迟重试时写回的字段。`bump` = 这次算一次投递（失败重试）；额度满了延后发的不算
    pub fn retry_fields(&self, bump: bool) -> Vec<(&'static str, Vec<u8>)> {
        let mut f: Vec<(&'static str, Vec<u8>)> = vec![
            ("id", self.id.clone().into_bytes()),
            ("from", self.from.clone().into_bytes()),
            ("to", self.to.clone().into_bytes()),
            ("not_after", self.not_after.to_string().into_bytes()),
            (
                "attempt",
                (self.attempt + u32::from(bump)).to_string().into_bytes(),
            ),
            ("meta", self.meta.clone().into_bytes()),
        ];
        if let Some(e) = &self.eml {
            f.push(("eml", e.clone()));
        }
        if let Some(p) = &self.eml_path {
            f.push(("eml_path", p.clone().into_bytes()));
        }
        f
    }
}

/// XREADGROUP 的回复 → 每个条目 (entry_id, 字段)。超时返回 Nil → 空
pub fn parse_xread(v: &Value) -> Vec<(String, Vec<(String, Value)>)> {
    let mut out = Vec::new();
    let streams: Vec<Value> = match v {
        Value::Array(items) => items.clone(),
        Value::Map(pairs) => pairs
            .iter()
            .map(|(k, v)| Value::Array(vec![k.clone(), v.clone()]))
            .collect(),
        _ => return out,
    };
    for s in streams {
        let Value::Array(parts) = s else { continue };
        if parts.len() != 2 {
            continue;
        }
        let Value::Array(entries) = &parts[1] else {
            continue;
        };
        for e in entries {
            let Value::Array(ep) = e else { continue };
            if ep.len() != 2 {
                continue;
            }
            let Some(eid) = text(&ep[0]) else { continue };
            out.push((eid, field_pairs(&ep[1])));
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bs(s: &str) -> Value {
        Value::BulkString(s.as_bytes().to_vec())
    }

    #[test]
    fn parses_xreadgroup_reply_and_entry() {
        let reply = Value::Array(vec![Value::Array(vec![
            bs("p:mail:relay:stream:bulk"),
            Value::Array(vec![Value::Array(vec![
                bs("1-0"),
                Value::Array(vec![
                    bs("id"),
                    bs("abc"),
                    bs("from"),
                    bs("a@x.test"),
                    bs("to"),
                    bs("b@y.test"),
                    bs("eml"),
                    bs("Subject: hi\r\n\r\nbody"),
                    bs("not_after"),
                    bs("0"),
                    bs("meta"),
                    bs("{}"),
                ]),
            ])]),
        ])]);
        let entries = parse_xread(&reply);
        assert_eq!(entries.len(), 1);
        let m = Mail::from_entry(Lane::Bulk, entries[0].0.clone(), &entries[0].1)
            .ok()
            .unwrap();
        assert_eq!(m.entry_id, "1-0");
        assert_eq!(m.id, "abc");
        assert_eq!(m.to, "b@y.test");
        assert_eq!(m.attempt, 0);
        assert_eq!(m.eml.as_deref(), Some(&b"Subject: hi\r\n\r\nbody"[..]));
        let retry = m.retry_fields(true);
        assert!(retry.iter().any(|(k, v)| *k == "attempt" && v == b"1"));
        let deferred = m.retry_fields(false);
        assert!(
            deferred.iter().any(|(k, v)| *k == "attempt" && v == b"0"),
            "额度满延后发的不算一次投递"
        );
    }

    #[test]
    fn trimmed_or_broken_entries_are_rejected() {
        assert!(Mail::from_entry(Lane::Bulk, "1-0".into(), &[]).is_err());
        let f = vec![
            ("id".to_string(), bs("x")),
            ("to".to_string(), bs("b@y.test")),
        ];
        let err = Mail::from_entry(Lane::Bulk, "1-0".into(), &f)
            .err()
            .unwrap();
        assert_eq!(err.id, "x");
        assert!(parse_xread(&Value::Nil).is_empty());
    }
}
