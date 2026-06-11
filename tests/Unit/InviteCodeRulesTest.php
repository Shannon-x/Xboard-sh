<?php

namespace Tests\Unit;

use App\Support\InviteCodeRules;
use PHPUnit\Framework\TestCase;

/**
 * 自定义邀请码格式规则（纯函数，不触 DB）。
 * 唯一性 / 墓碑恢复 / 并发抢注由 DB 唯一索引与控制器逻辑保证，不在本测试范围。
 */
class InviteCodeRulesTest extends TestCase
{
    public function test_valid_codes_pass(): void
    {
        foreach (['abcd', 'my-code', 'my_code_2026', 'A1b2C3', '1234', 'x'.str_repeat('y', 19)] as $code) {
            $this->assertNull(InviteCodeRules::validateFormat($code), "expected '{$code}' to be valid");
        }
    }

    public function test_length_bounds(): void
    {
        $this->assertNotNull(InviteCodeRules::validateFormat('abc'));               // 3 太短
        $this->assertNotNull(InviteCodeRules::validateFormat(str_repeat('a', 21))); // 21 太长
        $this->assertNull(InviteCodeRules::validateFormat('abcd'));                 // 4 边界
        $this->assertNull(InviteCodeRules::validateFormat(str_repeat('a', 20)));    // 20 边界
    }

    public function test_charset_restrictions(): void
    {
        foreach (['ab cd', 'ab.cd', 'ab@cd', '中文邀请码', 'ab/cd', "ab\ncd", 'ab+cd'] as $code) {
            $this->assertNotNull(InviteCodeRules::validateFormat($code), "expected '{$code}' to be rejected");
        }
        // 不能以 - 或 _ 开头（链接里易被吞掉/误解析）
        $this->assertNotNull(InviteCodeRules::validateFormat('-abcd'));
        $this->assertNotNull(InviteCodeRules::validateFormat('_abcd'));
        // 中间/结尾允许
        $this->assertNull(InviteCodeRules::validateFormat('a-b_c'));
    }

    public function test_reserved_words_rejected_case_insensitively(): void
    {
        foreach (['admin', 'Admin', 'ADMIN', 'official', 'Support', 'xboard'] as $code) {
            $this->assertNotNull(InviteCodeRules::validateFormat($code), "expected reserved '{$code}' to be rejected");
        }
        // 保留字作为子串不拦（admin2026 是合法品牌词用法）
        $this->assertNull(InviteCodeRules::validateFormat('admin2026'));
    }
}
