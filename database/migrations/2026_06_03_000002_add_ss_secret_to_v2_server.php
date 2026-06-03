<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 v2_server 增加可选的 ss_secret 列，用于 SS2022 server_key 强化。
 *
 * 历史实现 (Helper::getServerKey) 用 substr(md5($timestamp), 0, N) 派生 SS2022 server_key：
 *   - 输入域 = Unix 时间戳（≤2^32 整数空间）
 *   - md5 截位是 hex 字符串，128-gcm 实际熵 8 字节、256-gcm 16 字节
 *   - server_key 通过订阅接口下发给每个普通付费用户 → 公开可获取
 * 在合理算力范围内可枚举原 timestamp，导致 SS2022 端到端加密失效。
 *
 * 这一列默认 NULL，对历史节点完全透明（Helper::getServerKey 缺 secret 时回退旧行为，
 * 保证既有 SS 客户端不断连）。运维想加固某节点时，把 ss_secret 设置成 random_bytes(32)
 * 即可让该节点用 HMAC 派生 SS2022 key；订阅会下发新 key，客户端重新拉一次订阅即可。
 *
 * 不主动批量回填是因为：批量轮换会让所有 SS2022 客户端的现有连接立刻断开，必须给运维灰度。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_server')) {
            return;
        }
        if (Schema::hasColumn('v2_server', 'ss_secret')) {
            return;
        }
        Schema::table('v2_server', function (Blueprint $t) {
            $t->string('ss_secret', 64)->nullable()->after('protocol_settings');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_server') || !Schema::hasColumn('v2_server', 'ss_secret')) {
            return;
        }
        Schema::table('v2_server', function (Blueprint $t) {
            $t->dropColumn('ss_secret');
        });
    }
};
