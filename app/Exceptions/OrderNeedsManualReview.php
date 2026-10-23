<?php

namespace App\Exceptions;

/**
 * 已付款订单开通时遇到「不能自动开通、重试也不会自己好」的情况（线路权限变了、折抵源被占用、
 * 旧单价格对不上……），要交给人看。
 *
 * 和普通 RuntimeException 的区别只在 OrderHandleJob 那一层：普通异常照旧抛给队列重试、进 failed_jobs；
 * 这一类不抛——订单留在「开通中」，告警一次给管理员，条件恢复后下一轮 check:order 仍会自动开通。
 * 以前它们也是普通 RuntimeException，check:order 每分钟重派、每次失败三遍，2026-10 #3866 一张单两天刷了 700 多条 failed_jobs。
 */
class OrderNeedsManualReview extends \RuntimeException
{
}
