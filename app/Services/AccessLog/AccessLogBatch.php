<?php

namespace App\Services\AccessLog;

use InvalidArgumentException;

/**
 * 节点上传的一个批次：一行 JSON 头 + 32 个首尾相接的 gzip 成员（按 user_id % 32 分组）。
 *
 * 头：{"v":1,"ledger","seq","hour","from","to","records","dropped","groups":[[offset,length]×32],
 *      "users":[[user_id,records,up,down],…]}
 * 每个 gzip 成员解开是制表符分隔的行：
 *   user  start  seconds  tcp|udp  host  port  outbound  source  up  down  finished
 */
final class AccessLogBatch
{
    public const GROUPS = 32;
    /** 节点端单批原始数据上限 8 MiB，压缩后远小于此；头里的用户小计另算 */
    public const MAX_BODY = 24 * 1024 * 1024;
    public const COLUMNS = ['user_id', 'started_at', 'seconds', 'network', 'host', 'port', 'outbound', 'source_ip', 'up', 'down', 'finished'];

    /**
     * @param array<int,array{0:int,1:int}> $groups
     * @param array<int,array{0:int,1:int,2:int,3:int}> $users
     */
    private function __construct(
        public readonly string $ledger,
        public readonly int $seq,
        public readonly int $hour,
        public readonly int $from,
        public readonly int $to,
        public readonly int $records,
        public readonly int $dropped,
        public readonly array $groups,
        public readonly array $users,
        public readonly string $data,
    ) {
    }

    public static function parse(string $body, ?int $now = null): self
    {
        $now ??= now()->getTimestamp();
        if (strlen($body) > self::MAX_BODY) {
            throw new InvalidArgumentException('batch too large');
        }
        $split = strpos($body, "\n");
        if ($split === false) {
            throw new InvalidArgumentException('missing header');
        }
        $header = json_decode(substr($body, 0, $split), true, 8, JSON_BIGINT_AS_STRING);
        if (!is_array($header) || ($header['v'] ?? null) !== 1) {
            throw new InvalidArgumentException('unsupported header');
        }
        $data = substr($body, $split + 1);

        $ledger = $header['ledger'] ?? null;
        if (!is_string($ledger) || !preg_match('/^[0-9a-f]{32}$/', $ledger)) {
            throw new InvalidArgumentException('invalid ledger');
        }
        $int = static function (string $name) use ($header): int {
            $value = $header[$name] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException("invalid {$name}");
            }
            return $value;
        };
        $seq = $int('seq');
        $hour = $int('hour');
        // 节点在磁盘上最多积压到 1 GiB，按 8 天算足够；更早的或来自未来的批次视为时钟错误
        if ($hour % 3600 !== 0 || $hour < $now - 8 * 86400 || $hour > $now + 3600) {
            throw new InvalidArgumentException('invalid hour');
        }

        $groups = $header['groups'] ?? null;
        if (!is_array($groups) || count($groups) !== self::GROUPS || !array_is_list($groups)) {
            throw new InvalidArgumentException('invalid groups');
        }
        $expected = 0;
        foreach ($groups as $i => $range) {
            if (!is_array($range) || count($range) !== 2 || !is_int($range[0] ?? null) || !is_int($range[1] ?? null)
                || $range[0] !== $expected || $range[1] < 0) {
                throw new InvalidArgumentException('invalid groups');
            }
            $expected += $range[1];
            $groups[$i] = [$range[0], $range[1]];
        }
        if ($expected !== strlen($data)) {
            throw new InvalidArgumentException('groups do not cover the data');
        }

        $users = $header['users'] ?? null;
        if (!is_array($users) || !array_is_list($users)) {
            throw new InvalidArgumentException('invalid users');
        }
        $records = 0;
        foreach ($users as $i => $user) {
            if (!is_array($user) || count($user) !== 4) {
                throw new InvalidArgumentException('invalid users');
            }
            foreach ($user as $value) {
                if (!is_int($value) || $value < 0) {
                    throw new InvalidArgumentException('invalid users');
                }
            }
            if ($user[0] === 0) {
                throw new InvalidArgumentException('invalid users');
            }
            $users[$i] = [$user[0], $user[1], $user[2], $user[3]];
            $records += $user[1];
        }
        if ($records !== $int('records')) {
            throw new InvalidArgumentException('records do not match the users');
        }

        return new self(
            ledger: $ledger,
            seq: $seq,
            hour: $hour,
            from: $int('from'),
            to: $int('to'),
            records: $records,
            dropped: $int('dropped'),
            groups: $groups,
            users: $users,
            data: $data,
        );
    }

    public static function groupOf(int $userId): int
    {
        return $userId % self::GROUPS;
    }
}
