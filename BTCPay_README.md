# BTCPay Server 支付集成优化

## 概述

本次优化针对 Xboard 中的 BTCPay Server 支付集成进行了全面升级，修复了已知问题并添加了新功能。

## 主要改进

### 1. BTCPay Server API 升级

- **更新为 Greenfield API**: 弃用旧的 Legacy API，使用最新的 Greenfield API
- **改进的 API 密钥格式**: 支持新的 64 位 API 密钥格式（不再使用 `token_` 前缀）
- **增强的权限管理**: 明确指定所需的 API 权限

### 2. 支付流程优化

- **增强的错误处理**: 详细的错误信息和日志记录
- **改进的支付参数**: 支持更多的发票配置选项
- **交易确认策略**: 可配置的确认速度设置
- **多币种支持**: 支持BTCPay Server的所有可用加密货币

### 3. 安全增强

- **Webhook 签名验证**: 严格的HMAC签名验证
- **请求来源验证**: IP白名单和用户代理检查
- **错误信息脱敏**: 避免在错误响应中泄露敏感信息
- **SSL/TLS 验证**: 强制HTTPS连接验证

### 4. 开发工具

- **配置测试命令**: `php artisan btcpay:test` 用于测试配置
- **详细的配置文档**: 分步骤的配置指南
- **自动化配置脚本**: 一键配置和测试脚本
- **前端配置说明**: 管理界面使用指南

## 文件清单

### 核心文件
- `app/Payments/BTCPay.php` - BTCPay支付处理类（已优化）
- `app/Console/Commands/TestBTCPay.php` - 配置测试命令（新增）

### 配置文件
- `app/Console/Kernel.php` - 注册新命令（已更新）

### 文档文件
- `docs/BTCPay_Configuration_Guide.md` - 完整配置指南（已更新）
- `docs/BTCPay_Frontend_Configuration.md` - 前端配置说明（新增）
- `btcpay_setup.sh` - 自动化配置脚本（新增）

## 配置参数说明

### 基础配置

| 参数 | 说明 | 示例 |
|------|------|------|
| `btcpay_url` | BTCPay Server地址 | `https://btcpay.example.com/` |
| `btcpay_storeId` | 商店ID | `ABC123DEF456` |
| `btcpay_api_key` | Greenfield API密钥 | `1234567890abcdef...` |
| `btcpay_webhook_key` | Webhook验证密钥 | `random-secret-key` |

### 费用配置

| 参数 | 说明 | 单位 |
|------|------|------|
| `handling_fee_fixed` | 固定手续费 | 分 |
| `handling_fee_percent` | 百分比手续费 | % |

### 高级配置

| 参数 | 说明 | 用途 |
|------|------|------|
| `notify_domain` | 自定义通知域名 | CDN/反向代理场景 |

## 使用指南

### 1. 快速开始

```bash
# 运行配置脚本
bash btcpay_setup.sh

# 测试现有配置
php artisan btcpay:test --payment-id=1 --check-config

# 创建测试发票
php artisan btcpay:test --payment-id=1 --test-invoice
```

### 2. 手动配置

详细配置步骤请参考：`docs/BTCPay_Configuration_Guide.md`

### 3. 前端配置

管理界面配置说明请参考：`docs/BTCPay_Frontend_Configuration.md`

## 通知URL格式

### 标准通知URL
```
https://your-domain.com/api/v1/guest/payment/notify/BTCPay/{UUID}
```

### 自定义域名通知URL
```
https://custom-domain.com/api/v1/guest/payment/notify/BTCPay/{UUID}
```

## 故障排除

### 常见问题

1. **API连接失败**
   - 检查BTCPay Server地址是否正确
   - 验证网络连接和防火墙设置
   - 确认SSL证书有效

2. **权限错误**
   - 验证API密钥权限设置
   - 检查Store ID是否正确
   - 确认API密钥未过期

3. **Webhook通知失败**
   - 检查Webhook URL配置
   - 验证签名密钥设置
   - 查看服务器日志

### 日志检查

```bash
# 查看BTCPay相关日志
tail -f storage/logs/laravel.log | grep BTCPay

# 查看支付通知日志
grep "Payment notification" storage/logs/laravel.log

# 查看错误日志
grep "ERROR" storage/logs/laravel.log | grep -i btcpay
```

## 测试环境

### BTCPay Server 测试网络配置

1. 配置测试网络钱包
2. 设置测试币种
3. 创建测试发票
4. 验证回调流程

### 生产环境部署检查

- [ ] API密钥权限正确
- [ ] Webhook URL可访问
- [ ] SSL证书有效
- [ ] 防火墙规则配置
- [ ] 日志监控设置

## 安全建议

1. **API密钥管理**
   - 使用最小权限原则
   - 定期轮换密钥
   - 避免硬编码密钥

2. **Webhook安全**
   - 使用强随机密钥
   - 验证请求来源
   - 启用HTTPS

3. **服务器安全**
   - 配置防火墙
   - 监控异常访问
   - 定期安全更新

## 性能优化

1. **缓存策略**
   - 缓存BTCPay Server状态
   - 优化API请求频率

2. **监控告警**
   - 支付成功率监控
   - API响应时间监控
   - 错误率告警

## 版本兼容性

- **BTCPay Server**: v1.6.0+
- **PHP**: 7.4+
- **Laravel**: 8.0+
- **Xboard**: 当前版本

## 更新日志

### v1.1.0 (当前版本)
- 升级到Greenfield API
- 增强错误处理和日志记录
- 添加配置测试工具
- 优化安全验证
- 完善文档和脚本

### v1.0.0 (原版本)
- 基础BTCPay Server集成
- 支持基本支付功能

## 贡献

欢迎提交问题和改进建议。请确保：
- 详细描述问题或改进点
- 提供相关的日志信息
- 测试建议的更改

## 许可证

本项目遵循与 Xboard 相同的许可证。
