# Online Device Limit

## Data sources

The device-limit feature deliberately separates configuration, real-time state,
and reporting data:

| Data | Source | Purpose |
| --- | --- | --- |
| `v2_user.device_limit` | Database | The configured maximum number of devices for a user |
| `user_devices:{userId}` | Redis | The authoritative real-time online device state |
| `v2_user.online_count` | Database | An eventually consistent snapshot for the admin UI and statistics |

`online_count` is not used to accept or reject a connection. A compatible node
receives `device_limit` in the user list and obtains the current count from the
panel's `/alivelist` endpoint. The node compares those two values when a new IP
connects.

## Redis representation

`DeviceStateService` stores a Redis hash per user:

```text
user_devices:{userId}
  {nodeId}:{normalizedIp} => last_report_unix_timestamp
```

The hash TTL is 300 seconds. Timestamps are also checked while reading, so a
stale field is not counted even if another node refreshes the hash TTL.

Each node also has a reverse index:

```text
user_devices:node:{nodeId} => SET<userId>
```

This lets the panel calculate full-snapshot differences and clear a disconnected
node without using Redis `KEYS`, which would block a shared Redis instance.

The `device_limit_mode` setting controls counting:

- `0`: strict, count every node and IP pair.
- `1`: deduplicate the exact same IP across nodes.
- `2`: deduplicate IPv4 `/24` or IPv6 `/64` subnets across nodes.

## Report and enforcement flow

1. The user list endpoint reads `device_limit` from `v2_user` and sends it to the
   node.
2. Nodes periodically submit a full `{userId: [ip, ...]}` device snapshot via
   HTTP `/alive`, the V2 combined report, or WebSocket `report.devices`.
3. The panel applies a per-node difference: users missing from the new snapshot
   are removed from that node, and present users are replaced with the new IPs.
4. `/alivelist` reads Redis and returns only current, non-expired counts.
5. The node rejects a new IP when the database-configured `device_limit` has
   already been reached by the Redis-derived alive count.

A missing `alive` field in a combined report means "no device data was included"
and does not clear state. An explicitly empty object or array is a full empty
snapshot and clears the reporting node's state.

## Database snapshot convergence

Redis expiration is passive: when a key reaches its TTL, Redis does not execute
PHP and therefore cannot directly set `v2_user.online_count` to zero. To keep the
admin snapshot accurate, two mechanisms are used:

- Device reports call `notifyUpdate()`, which mirrors the current Redis count to
  the database with a 10-second write throttle.
- `device:reconcile-online-counts` runs every minute. It checks rows whose stored
  count is positive or which were recently active, fetches Redis hashes in
  batches with a pipeline, and updates only counts that differ.

The Laravel scheduler must be running in production. A typical cron entry is:

```cron
* * * * * cd /path/to/xboard && php artisan schedule:run >> /dev/null 2>&1
```

If the scheduler is stopped, device enforcement still uses Redis and remains
correct, but `online_count` in the admin UI can retain an old positive value
after Redis expires. Restarting the scheduler or manually running the following
command repairs those snapshots:

```bash
php artisan device:reconcile-online-counts
```

The optional `--chunk` value controls database rows per Redis pipeline and must
be between 1 and 5000 (default: 500).
