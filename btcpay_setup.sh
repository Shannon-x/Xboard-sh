#!/bin/bash

# BTCPay Server 快速配置和测试脚本
# 使用方法: bash btcpay_setup.sh

echo "================================================"
echo "BTCPay Server 配置和测试脚本"
echo "================================================"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 检查函数
check_command() {
    if command -v $1 &> /dev/null; then
        echo -e "${GREEN}✓${NC} $1 已安装"
        return 0
    else
        echo -e "${RED}✗${NC} $1 未安装"
        return 1
    fi
}

# 检查依赖
echo -e "\n${BLUE}1. 检查系统依赖...${NC}"
check_command "php"
check_command "curl"
check_command "artisan" || echo -e "${YELLOW}⚠${NC} 请在Laravel项目根目录运行此脚本"

# 检查Laravel环境
echo -e "\n${BLUE}2. 检查Laravel环境...${NC}"
if [ -f "artisan" ]; then
    echo -e "${GREEN}✓${NC} Laravel项目检测成功"
    PHP_VERSION=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1,2)
    echo -e "${GREEN}✓${NC} PHP版本: $PHP_VERSION"
else
    echo -e "${RED}✗${NC} 未检测到Laravel项目，请确保在项目根目录运行"
    exit 1
fi

# 检查BTCPay支付类
echo -e "\n${BLUE}3. 检查BTCPay支付类...${NC}"
if [ -f "app/Payments/BTCPay.php" ]; then
    echo -e "${GREEN}✓${NC} BTCPay支付类存在"
else
    echo -e "${RED}✗${NC} BTCPay支付类不存在"
    exit 1
fi

# 检查测试命令
echo -e "\n${BLUE}4. 检查测试命令...${NC}"
if [ -f "app/Console/Commands/TestBTCPay.php" ]; then
    echo -e "${GREEN}✓${NC} BTCPay测试命令存在"
else
    echo -e "${RED}✗${NC} BTCPay测试命令不存在"
fi

# 运行基础测试
echo -e "\n${BLUE}5. 运行基础测试...${NC}"
php artisan list | grep -q "btcpay:test"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} BTCPay测试命令已注册"
    
    # 显示可用的BTCPay支付方式
    echo -e "\n${YELLOW}检查现有BTCPay配置:${NC}"
    php artisan btcpay:test
else
    echo -e "${YELLOW}⚠${NC} BTCPay测试命令未注册，请运行 'php artisan config:clear' 后重试"
fi

# 配置向导
echo -e "\n${BLUE}6. 配置向导${NC}"
echo "请选择操作："
echo "1) 测试现有BTCPay配置"
echo "2) 显示配置文档"
echo "3) 生成示例配置"
echo "4) 退出"

read -p "请输入选择 (1-4): " choice

case $choice in
    1)
        echo -e "\n${YELLOW}请输入BTCPay支付方式ID:${NC}"
        read -p "支付方式ID: " payment_id
        
        if [ ! -z "$payment_id" ]; then
            echo -e "\n${BLUE}测试配置连通性...${NC}"
            php artisan btcpay:test --payment-id=$payment_id --check-config
            
            echo -e "\n${BLUE}测试创建发票...${NC}"
            read -p "是否测试创建发票? (y/N): " test_invoice
            if [[ $test_invoice =~ ^[Yy]$ ]]; then
                php artisan btcpay:test --payment-id=$payment_id --test-invoice
            fi
        fi
        ;;
    2)
        echo -e "\n${BLUE}配置文档位置:${NC}"
        echo "• 完整配置指南: docs/BTCPay_Configuration_Guide.md"
        echo "• 前端配置说明: docs/BTCPay_Frontend_Configuration.md"
        echo "• 管理界面说明: docs/BTCPay_Admin_Interface.md"
        
        if [ -f "docs/BTCPay_Configuration_Guide.md" ]; then
            echo -e "\n${GREEN}文档已存在，可以查看详细配置说明${NC}"
        else
            echo -e "\n${RED}配置文档不存在${NC}"
        fi
        ;;
    3)
        echo -e "\n${BLUE}生成示例配置...${NC}"
        cat << EOF

=== BTCPay Server 示例配置 ===

1. 数据库配置 (v2_payment表):
INSERT INTO v2_payment (
    uuid, 
    payment, 
    name, 
    icon, 
    handling_fee_fixed, 
    handling_fee_percent, 
    enable, 
    sort, 
    config, 
    notify_domain
) VALUES (
    'btcpay001',
    'BTCPay',
    '加密货币支付',
    'https://example.com/btc-icon.png',
    0,
    0,
    1,
    1,
    '{"btcpay_url":"https://btcpay.example.com/","btcpay_storeId":"YOUR_STORE_ID","btcpay_api_key":"YOUR_API_KEY","btcpay_webhook_key":"YOUR_WEBHOOK_SECRET"}',
    NULL
);

2. BTCPay Server Webhook配置:
端点URL: https://yoursite.com/api/v1/guest/payment/notify/BTCPay/btcpay001
事件: Invoice Settled, Invoice Processing
内容类型: application/json
密钥: YOUR_WEBHOOK_SECRET

3. 测试命令:
php artisan btcpay:test --payment-id=1 --check-config
php artisan btcpay:test --payment-id=1 --test-invoice

EOF
        ;;
    4)
        echo -e "${GREEN}退出${NC}"
        exit 0
        ;;
    *)
        echo -e "${RED}无效选择${NC}"
        ;;
esac

# 显示有用的命令
echo -e "\n${BLUE}有用的命令:${NC}"
echo "• 查看日志: tail -f storage/logs/laravel.log | grep BTCPay"
echo "• 清除缓存: php artisan config:clear && php artisan cache:clear"
echo "• 测试配置: php artisan btcpay:test --payment-id=<ID> --check-config"
echo "• 创建测试发票: php artisan btcpay:test --payment-id=<ID> --test-invoice"

echo -e "\n${GREEN}配置完成！${NC}"
echo -e "如有问题，请查看配置文档或检查日志文件。"
