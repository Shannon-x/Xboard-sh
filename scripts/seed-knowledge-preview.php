<?php

/** Local-only, opt-in fixture. Never bootstrap a real deployment's .env. */
if (getenv('APP_ENV') !== 'local' || getenv('KNOWLEDGE_PREVIEW_FIXTURE') !== '1' ||
    getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== 'database/knowledge-preview.sqlite' ||
    is_file(__DIR__ . '/../.env') || is_file(__DIR__ . '/../bootstrap/cache/config.php')) {
    fwrite(STDERR, "Refusing: use the documented isolated local SQLite preview environment, without a .env file.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
$database = __DIR__ . '/../database/knowledge-preview.sqlite';
if (!is_file($database)) {
    touch($database);
}
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('migrate', ['--force' => true]);
if (App\Models\Knowledge::count() !== 0) {
    fwrite(STDERR, "Fixture database already contains articles; no records were changed.\n");
    exit(0);
}
$service = app(App\Services\KnowledgePublicationService::class);
$fixtures = [
    ['first-connection', '开始使用', '第一次连接，从这里开始（演示）', '将第一次使用拆成三个简单步骤：确认套餐、准备客户端、验证连接。',
        "## 确认套餐\n\n先在个人面板确认订阅是否生效，查看有效期和可用额度。\n\n## 准备订阅\n\n按照你的设备系统选择对应教程。请勿在公开场合分享个人订阅链接或二维码。\n\n## 验证连接\n\n更新订阅后选择可用线路。若仍有问题，请记录错误提示并联系支持。"],
    ['traffic-and-reset', '日常使用', '流量与重置日期怎么看（演示）', '区分剩余流量、套餐有效期和流量重置，使用起来更清楚。',
        "## 查看剩余流量\n\n个人面板提供当前额度，流量记录帮助你了解用量。\n\n## 分清两个日期\n\n套餐到期与流量重置不一定是同一天，请以账户页面的实际信息为准。\n\n## 用量不够怎么办\n\n先比较可用的升级、提前重置或加购选项，在确认金额后再操作。"],
    ['balance-and-renewal', '账户与续费', '充值与自动续费的区别（演示）', '充值是存入余额，续费才会延长套餐，请留意最终订单与到期日期。',
        "## 充值到余额\n\n充值成功本身不等于套餐已经续费。\n\n## 主动开启自动续费\n\n开启后，系统按后台规则在余额充足时扣款续费。\n\n## 确认结果\n\n请查看订单状态和新的到期时间，不要只看充值是否成功。"],
];
foreach ($fixtures as $sort => [$slug, $category, $title, $summary, $body]) {
    $article = new App\Models\Knowledge(compact('slug', 'category', 'title', 'summary', 'body') +
        ['language' => 'zh-CN', 'visibility' => 'public', 'show' => true, 'sort' => $sort]);
    $service->validateForSave($article, true);
    $article->save();
}
foreach (['members', 'subscribers', 'admin'] as $visibility) {
    App\Models\Knowledge::create(['title' => '不可公开的权限验证样例（演示）', 'category' => '私有演示',
        'language' => 'zh-CN', 'body' => 'Private fixture: never return this through guest endpoints.',
        'visibility' => $visibility, 'show' => true]);
}
fwrite(STDOUT, "Created 3 clearly labeled public demo guides and 3 private authorization fixtures in the isolated preview database.\n");
