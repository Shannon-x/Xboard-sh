#!/bin/bash

# BTCPay Server 配置快速测试脚本
# 使用方法: ./btcpay_quick_test.sh

echo "=== BTCPay Server 配置测试脚本 ==="
echo ""

# 检查环境
echo "1. 检查环境..."

if ! command -v php &> /dev/null; then
    echo "❌ PHP 未安装或不在 PATH 中"
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo "❌ Composer 未安装或不在 PATH 中"
    exit 1
fi

echo "✓ PHP 版本: $(php --version | head -n1)"
echo "✓ Composer 可用"

# 检查Laravel应用
echo ""
echo "2. 检查Laravel应用..."

if [ ! -f "artisan" ]; then
    echo "❌ 不在Laravel项目根目录"
    exit 1
fi

echo "✓ Laravel项目根目录确认"

# 检查数据库连接
echo ""
echo "3. 检查数据库连接..."

php artisan migrate:status > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✓ 数据库连接正常"
else
    echo "❌ 数据库连接失败，请检查.env配置"
    exit 1
fi

# 列出现有的BTCPay支付配置
echo ""
echo "4. 查找BTCPay支付配置..."

BTCPAY_PAYMENTS=$(php artisan tinker --execute="echo App\\Models\\Payment::where('payment', 'BTCPay')->get(['id', 'name', 'enable'])->toJson();" 2>/dev/null)

if [ "$BTCPAY_PAYMENTS" = "[]" ]; then
    echo "⚠ 未找到BTCPay支付配置"
    echo "请先在管理后台创建BTCPay支付方式"
    echo ""
    echo "配置步骤："
    echo "1. 登录管理后台"
    echo "2. 进入 支付设置 -> 支付方式"
    echo "3. 点击 添加支付方式"
    echo "4. 选择 BTCPay 接口"
    echo "5. 填写配置参数"
    echo ""
    exit 1
else
    echo "✓ 找到BTCPay支付配置:"
    echo "$BTCPAY_PAYMENTS" | php -r "
        \$data = json_decode(file_get_contents('php://stdin'), true);
        foreach (\$data as \$payment) {
            echo sprintf('  ID: %d, 名称: %s, 状态: %s', \$payment['id'], \$payment['name'], \$payment['enable'] ? '启用' : '禁用') . \"\\n\";
        }
    "
fi

# 获取第一个BTCPay配置ID进行测试
PAYMENT_ID=$(echo "$BTCPAY_PAYMENTS" | php -r "echo json_decode(file_get_contents('php://stdin'), true)[0]['id'];")

echo ""
echo "5. 测试BTCPay连接 (使用ID: $PAYMENT_ID)..."

php artisan btcpay:test $PAYMENT_ID
TEST_RESULT=$?

echo ""
if [ $TEST_RESULT -eq 0 ]; then
    echo "🎉 BTCPay配置测试通过！"
    echo ""
    echo "下一步："
    echo "1. 在BTCPay Server中配置Webhook"
    echo "2. 创建测试订单验证支付流程"
    echo "3. 监控日志文件: tail -f storage/logs/laravel.log | grep BTCPay"
else
    echo "❌ BTCPay配置测试失败"
    echo ""
    echo "请检查:"
    echo "1. BTCPay Server URL是否正确"
    echo "2. Store ID是否正确"
    echo "3. API Key是否有效且具有必要权限"
    echo "4. 网络连接是否正常"
fi

echo ""
echo "=== 测试完成 ==="
