# BTCPay Server 支付配置指南

本文档详细说明如何正确配置BTCPay Server支付接口。

## 配置参数说明

### 1. 通知域名 (Notify Domain)
**作用：** 网关通知将发送到该域名
**配置说明：**
- 如果您的站点通过CDN或反向代理访问，可能需要配置通知域名
- 通知域名应该是BTCPay Server能够直接访问的域名
- 格式：`https://api.yoursite.com` 或 `https://yoursite.com`
- 留空则使用默认的站点域名

**示例：**
- 如果站点域名是 `https://yoursite.com`，但后端API在 `https://api.yoursite.com`
- 则应配置通知域名为：`https://api.yoursite.com`

### 2. 百分比手续费 (%)
**作用：** 按订单金额比例收取的手续费
**配置说明：**
- 范围：0-100 之间的数值
- 例如：设置为 2，表示收取订单金额2%的手续费
- 如果订单金额100元，手续费为2元，用户实际支付102元

### 3. 固定手续费
**作用：** 每笔订单固定收取的手续费
**配置说明：**
- 单位：分（1元 = 100分）
- 例如：设置为 50，表示每笔订单收取0.5元固定手续费
- 与百分比手续费可同时使用

**计算公式：** 最终支付金额 = 订单金额 + 固定手续费 + (订单金额 × 百分比手续费 ÷ 100)

### 4. 支付接口选择
**配置：** BTCPay
**说明：** 选择BTCPay作为支付处理器

## BTCPay Server 配置

### 1. API接口所在网址
**格式：** `https://your-btcpay-server.com/`
**注意：** 必须包含最后的斜杠 `/`

**获取方法：**
1. 登录您的BTCPay Server实例
2. 复制浏览器地址栏中的网址
3. 确保以 `/` 结尾

### 2. Store ID
**位置：** 商店设置 → 一般设置 → Store ID
**格式：** 通常是一串随机字符，类似 `ABCDEFGHijklmnop123456789`

**获取步骤：**
1. 登录BTCPay Server
2. 选择您的商店
3. 进入 "设置" → "一般"
4. 复制 "Store ID" 字段的值

### 3. Greenfield API 密钥
**位置：** 账户设置 → 管理访问令牌
**格式：** 64位字符串（不以token_开头）

**创建步骤：**
1. 登录BTCPay Server
2. 点击右上角用户头像 → "账户设置"
3. 选择 "管理访问令牌" 标签页
4. 点击 "创建新令牌"
5. 设置标签（如：Xboard Payment）
6. **必须选择以下权限：**
   - `btcpay.store.cancreateinvoice` - 创建发票
   - `btcpay.store.canviewinvoices` - 查看发票
7. 点击 "请求授权" 并确认
8. 复制生成的Greenfield API密钥（64位字符串）

### 4. WEBHOOK SECRET
**位置：** 商店设置 → Webhooks
**格式：** 自定义的随机密钥字符串

**配置步骤：**
1. 进入商店设置 → "Webhooks"
2. 点击 "创建 Webhook"
3. 设置以下参数：
   - **Payload URL：** `https://yoursite.com/api/v1/guest/payment/notify/BTCPay/{UUID}`
     > 注意：{UUID} 会在保存支付配置后自动生成，首次配置时先留空，保存后复制实际的通知URL
   - **Secret：** 生成一个随机密钥（建议32位以上的随机字符串）
   - **Events：** 选择以下事件：
     - `Invoice settlement` - 发票结算
     - `Invoice processing` - 发票处理中
     - `Invoice expired` - 发票过期
   - **Content Type：** `application/json`
4. 保存Webhook配置
5. 将设置的Secret复制到Xboard的WEBHOOK SECRET字段

## 完整配置流程

### 第一步：在Xboard中创建BTCPay支付方式
1. 登录Xboard管理后台
2. 进入 "支付设置" → "支付方式"
3. 点击 "添加支付方式"
4. 选择 "BTCPay" 作为支付接口
5. 填写基本信息：
   - 显示名称：如 "比特币支付"
   - 图标：可选
6. 暂时先填写BTCPay配置（除了WEBHOOK SECRET）
7. 保存配置

### 第二步：获取通知URL
1. 保存后，在支付方式列表中找到刚创建的BTCPay配置
2. 复制显示的 "通知URL"，格式类似：
   `https://yoursite.com/api/v1/guest/payment/notify/BTCPay/abc12345`

### 第三步：在BTCPay Server中配置Webhook
1. 使用第二步获取的URL配置Webhook
2. 设置Secret密钥
3. 保存Webhook配置

### 第四步：完善Xboard配置
1. 将BTCPay Server中设置的Webhook Secret填入Xboard的WEBHOOK SECRET字段
2. 保存配置

## 测试配置

### 1. 创建测试订单
1. 在前台创建一个小额订单
2. 选择BTCPay支付方式
3. 检查是否能正常跳转到BTCPay支付页面

### 2. 完成测试支付
1. 在BTCPay支付页面完成支付（测试环境可使用测试币）
2. 检查支付状态是否正确更新
3. 查看日志确认通知是否正常接收

### 3. 检查日志
**Xboard日志位置：** `storage/logs/laravel.log`
**关键日志：**
- `BTCPay通知：支付成功` - 表示通知处理成功
- `BTCPay通知：签名验证失败` - 表示Webhook Secret配置错误
- `BTCPay Server错误` - 表示API配置有问题

## 常见问题

### Q1: 提示"API密钥无效或已过期"
**解决方案：**
1. 检查API Key是否正确复制
2. 确认API Key具有必要权限
3. 检查BTCPay Server API地址是否正确

### Q2: 支付成功但订单状态未更新
**解决方案：**
1. 检查Webhook URL是否正确配置
2. 确认Webhook Secret是否匹配
3. 查看BTCPay Server的Webhook日志
4. 检查Xboard的日志文件

### Q3: 提示"商店ID不存在"
**解决方案：**
1. 重新检查并复制正确的Store ID
2. 确认当前API Key对该商店有访问权限

### Q4: 通知URL无法访问
**解决方案：**
1. 检查服务器防火墙设置
2. 确认BTCPay Server能够访问您的服务器
3. 如使用CDN，配置通知域名为源服务器地址

## 安全建议

1. **定期更换API Key：** 建议每6个月更换一次API访问令牌
2. **使用HTTPS：** 确保所有通信都通过HTTPS进行
3. **监控日志：** 定期检查支付相关日志，及时发现异常
4. **备份配置：** 保存好所有配置参数，便于故障恢复

## 支持的币种

BTCPay Server支持多种加密货币：
- Bitcoin (BTC)
- Lightning Network (LN)
- Litecoin (LTC)
- Ethereum (ETH)
- 以及其他BTCPay Server支持的币种

用户在支付时可以选择任意支持的币种进行支付。
