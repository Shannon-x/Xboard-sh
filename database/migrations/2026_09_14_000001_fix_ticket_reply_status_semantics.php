<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 把 v2_ticket.reply_status 的存储语义扳回列注释写的那套：0 待回复 / 1 已回复。
 *
 * 历史上 TicketService 给 reply_status 赋的是 status 字段的常量
 * （STATUS_OPENING=0 / STATUS_CLOSED=1），管理员回复写 0、用户回复写 1，
 * 于是库里的实际语义与列注释正好相反；列默认值又是 1，而 createTicket()
 * 根本不写这个字段。后台前端按「反」的语义渲染所以看着正常，用户前端按
 * 注释语义渲染 —— 结果每张新建工单、以及用户每次追问之后，用户端都立刻
 * 显示「官方已回复」，而客服一个字都没回。
 *
 * 代码侧已改成写 Ticket::REPLY_STATUS_PENDING/REPLIED，这里把存量数据翻转过来。
 *
 * 幂等：用列默认值当标记 —— 默认值还是 1 说明没扳过，翻转并把默认值改成 0；
 * 已经是 0 就直接跳过。这样即便先在生产手工跑过等效 SQL，迁移再跑也不会
 * 把数据二次翻转（翻两次就等于没改，而且没人会注意到）。
 *
 * 部署顺序要求：先上新代码再跑本迁移。反过来的话，翻转后的几秒里旧代码仍按
 * 旧语义写入，新进来的工单状态会是错的。
 */
return new class extends Migration {
    private const TABLE = 'v2_ticket';
    private const COLUMN = 'reply_status';

    public function up(): void
    {
        $this->flipTo(0);
    }

    public function down(): void
    {
        $this->flipTo(1);
    }

    /**
     * @param int $targetDefault 扳完之后列默认值应该是多少；同时用作「是否已扳过」的判据
     */
    private function flipTo(int $targetDefault): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $current = $this->currentDefault();
        if ($current !== null && $current === $targetDefault) {
            return; // 已经是目标状态，别再翻一次
        }

        DB::table(self::TABLE)
            ->whereIn(self::COLUMN, [0, 1])
            ->update([self::COLUMN => DB::raw('1 - ' . self::COLUMN)]);

        Schema::table(self::TABLE, function (Blueprint $table) use ($targetDefault) {
            $table->integer(self::COLUMN)
                ->default($targetDefault)
                ->comment($targetDefault === 0 ? '0:待回复 1:已回复' : '0:已回复 1:待回复')
                ->change();
        });
    }

    /**
     * 列当前的默认值；判不出来就返回 null（判不出来时按「还没扳过」处理，
     * 迁移表本身保证了只会跑一次）。
     */
    private function currentDefault(): ?int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = $connection->selectOne(
                'SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$connection->getDatabaseName(), self::TABLE, self::COLUMN]
            );
            $default = $row->d ?? null;
            return $default === null ? null : (int) $default;
        }

        if ($driver === 'sqlite') {
            foreach ($connection->select('PRAGMA table_info(' . self::TABLE . ')') as $row) {
                if (($row->name ?? '') === self::COLUMN) {
                    $default = $row->dflt_value ?? null;
                    return $default === null ? null : (int) trim((string) $default, "'\"");
                }
            }
            return null;
        }

        if ($driver === 'pgsql') {
            $row = $connection->selectOne(
                'SELECT column_default AS d FROM information_schema.columns
                 WHERE table_name = ? AND column_name = ?',
                [self::TABLE, self::COLUMN]
            );
            $default = $row->d ?? null;
            return $default === null ? null : (int) $default;
        }

        return null;
    }
};
