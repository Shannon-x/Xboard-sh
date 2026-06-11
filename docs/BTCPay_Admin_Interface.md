# BTCPay Server 前端配置界面说明

本文档说明管理后台BTCPay支付配置界面的各个字段。

## 基本信息配置

### 显示名称
- **字段：** `name`
- **说明：** 在前台支付选择页面显示的名称
- **示例：** "比特币支付"、"加密货币支付"、"BTCPay"

### 图标
- **字段：** `icon`
- **说明：** 支付方式的图标URL（可选）
- **格式：** HTTP/HTTPS链接或相对路径
- **示例：** `https://example.com/btc-icon.png`

## 手续费配置

### 固定手续费
- **字段：** `handling_fee_fixed`
- **说明：** 每笔订单收取的固定手续费
- **单位：** 分（1元 = 100分）
- **示例：** 输入 `50` 表示每笔订单收取0.5元手续费

### 百分比手续费
- **字段：** `handling_fee_percent`
- **说明：** 按订单金额百分比收取的手续费
- **范围：** 0-100 的数值
- **示例：** 输入 `2` 表示收取订单金额2%的手续费

## 高级配置

### 通知域名
- **字段：** `notify_domain`
- **说明：** BTCPay Server发送支付通知的目标域名
- **格式：** `https://domain.com`（不包含路径）
- **使用场景：**
  - 站点使用CDN且后端API有独立域名
  - 反向代理配置
  - 内网部署需要外网通知

**配置示例：**
```
站点域名：https://mysite.com
后端API：https://api.mysite.com
配置值：https://api.mysite.com
```

## BTCPay Server 特定配置

### API接口所在网址
- **字段：** `btcpay_url`
- **说明：** BTCPay Server实例的完整URL
- **格式：** `https://btcpay.domain.com/`
- **注意：** 必须以斜杠 `/` 结尾

### Store ID
- **字段：** `btcpay_storeId`
- **说明：** BTCPay Server中的商店标识符
- **获取：** 商店设置 → 一般设置 → Store ID
- **格式：** 字母数字组合的字符串

### API KEY
- **字段：** `btcpay_api_key`
- **说明：** BTCPay Server API访问令牌
- **权限要求：**
  - `btcpay.store.cancreateinvoice`
  - `btcpay.store.canviewinvoices`
- **格式：** 以 `token_` 开头的字符串

### WEBHOOK SECRET
- **字段：** `btcpay_webhook_key`
- **说明：** Webhook通知的验证密钥
- **作用：** 确保通知来自合法的BTCPay Server
- **生成：** 使用强随机字符串（建议32位以上）

## 配置验证

保存配置后，系统会显示完整的通知URL，格式如下：
```
https://yoursite.com/api/v1/guest/payment/notify/BTCPay/{UUID}
```

这个URL需要在BTCPay Server的Webhook配置中使用。

## 状态控制

### 启用/禁用
- 保存后可通过开关控制支付方式的启用状态
- 禁用后前台不会显示该支付选项
- 已有订单的支付流程不会受影响

### 排序
- 可拖拽调整支付方式在前台的显示顺序
- 排序靠前的支付方式优先显示

## 测试配置

配置完成后，建议：

1. **使用测试命令验证连接：**
   ```bash
   php artisan btcpay:test {payment_id}
   ```

2. **创建小额测试订单：**
   - 选择BTCPay支付
   - 确认能正常跳转到支付页面
   - 完成支付后检查订单状态

3. **检查日志：**
   ```bash
   tail -f storage/logs/laravel.log | grep BTCPay
   ```

## 故障排除

### 常见错误信息

- **"API密钥无效"** → 检查API Key配置和权限
- **"商店ID不存在"** → 确认Store ID正确
- **"签名验证失败"** → 检查Webhook Secret配置
- **"连接失败"** → 检查BTCPay Server URL和网络连接

### 日志关键词

在 `storage/logs/laravel.log` 中搜索：
- `BTCPay通知：` - 通知处理日志
- `BTCPay Server错误：` - API错误
- `Payment notification` - 支付通知日志
