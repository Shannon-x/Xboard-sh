<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2_invite_code.code 唯一索引。
 *
 * 背景：自定义邀请码功能要求 code 全局唯一。原表无任何索引约束，
 * 随机 8 位码理论上可碰撞，自定义码更需要 DB 级防抢注/防并发双写。
 *
 * 升级安全：
 *   - 幂等：索引已存在 / 表不存在时直接跳过，重复执行无副作用。
 *   - 建索引前先去重：同 code 多行时保留「status=0（未用、可能正在外部传播）优先，
 *     其次 id 最小」的一行，其余行重生成随机码（code 字符串本身无外键引用，
 *     邀请关系存于 v2_user.invite_user_id，改写历史行的 code 不影响任何数据链路）。
 *   - MySQL ci collation 下唯一索引天然大小写不敏感（"Abc" 与 "abc" 视为重复），
 *     与应用层 LOWER() 预检查语义一致；SQLite 为大小写敏感，由应用层预检查补齐。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_invite_code')) {
            return;
        }
        if ($this->indexExists('uniq_invite_code')) {
            return;
        }

        // 1) 去重（ci collation 下 GROUP BY code 即大小写不敏感分组）
        $dupCodes = DB::table('v2_invite_code')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        foreach ($dupCodes as $dupCode) {
            $rows = DB::table('v2_invite_code')
                ->where('code', $dupCode)
                ->orderBy('status') // status=0 优先保留（活跃码的外流链接不能失效）
                ->orderBy('id')
                ->get(['id']);
            $rows->shift(); // 第一行保留原 code，其余重生成

            foreach ($rows as $row) {
                do {
                    $newCode = $this->randomCode();
                } while (DB::table('v2_invite_code')->where('code', $newCode)->exists());

                DB::table('v2_invite_code')->where('id', $row->id)->update([
                    'code' => $newCode,
                    'updated_at' => time(),
                ]);
            }
        }

        // 2) 唯一索引（try/catch 兜底：极端竞态/历史脏索引导致已存在时，不让整次升级 migrate 中断）
        try {
            Schema::table('v2_invite_code', function ($table) {
                $table->unique('code', 'uniq_invite_code');
            });
        } catch (\Throwable $e) {
            if (!$this->indexExists('uniq_invite_code')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_invite_code')) {
            return;
        }
        if (!$this->indexExists('uniq_invite_code')) {
            return;
        }
        Schema::table('v2_invite_code', function ($table) {
            $table->dropUnique('uniq_invite_code');
        });
    }

    /**
     * 跨版本/驱动安全的索引存在性判断（与本仓既有迁移一致的防御写法）。
     */
    private function indexExists(string $indexName): bool
    {
        if (method_exists(Schema::class, 'hasIndex')) {
            try {
                return Schema::hasIndex('v2_invite_code', $indexName);
            } catch (\Throwable $e) {
                // 落到下面的 information_schema / pragma 兜底
            }
        }

        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                $rows = Schema::getConnection()->select(
                    "SELECT name FROM sqlite_master WHERE type='index' AND name = ?",
                    [$indexName]
                );
                return !empty($rows);
            }
            $rows = Schema::getConnection()->select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                ['v2_invite_code', $indexName]
            );
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function randomCode(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
};
