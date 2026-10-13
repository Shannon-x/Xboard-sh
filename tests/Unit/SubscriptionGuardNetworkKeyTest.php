<?php

namespace Tests\Unit;

use App\Services\SubscriptionGuard;
use PHPUnit\Framework\TestCase;

/**
 * 共享订阅检测把来源 IP 收敛到「网络」粒度（IPv4 /24、IPv6 /48），
 * 让单个用户的动态 IP / 移动漫游算作同一个网络、不误报，而被转卖的
 * 订阅横跨几十个互不相关网络时才累计。
 */
class SubscriptionGuardNetworkKeyTest extends TestCase
{
    public function test_ipv4_collapses_to_slash24(): void
    {
        // 同一 /24 内的不同主机号 → 同一个网络键
        $this->assertSame(
            SubscriptionGuard::networkKey('203.0.113.5'),
            SubscriptionGuard::networkKey('203.0.113.200')
        );
        $this->assertSame('v4:203.0.113', SubscriptionGuard::networkKey('203.0.113.5'));
    }

    public function test_ipv4_different_slash24_differs(): void
    {
        $this->assertNotSame(
            SubscriptionGuard::networkKey('203.0.113.5'),
            SubscriptionGuard::networkKey('203.0.114.5')
        );
    }

    public function test_ipv6_collapses_to_slash48(): void
    {
        // 同一 /48 内不同后缀（含简写形式）→ 同一个网络键
        $a = SubscriptionGuard::networkKey('2001:db8:abcd:0001::1');
        $b = SubscriptionGuard::networkKey('2001:db8:abcd:ffff:5678::9');
        $this->assertSame($a, $b);
        $this->assertSame('v6:20010db8abcd', $a);
    }

    public function test_ipv6_different_slash48_differs(): void
    {
        $this->assertNotSame(
            SubscriptionGuard::networkKey('2001:db8:abcd::1'),
            SubscriptionGuard::networkKey('2001:db8:abce::1')
        );
    }

    public function test_blank_and_garbage_are_safe(): void
    {
        $this->assertSame('unknown', SubscriptionGuard::networkKey(''));
        $this->assertSame('unknown', SubscriptionGuard::networkKey('   '));
        $this->assertSame('other:not-an-ip', SubscriptionGuard::networkKey('not-an-ip'));
    }
}
