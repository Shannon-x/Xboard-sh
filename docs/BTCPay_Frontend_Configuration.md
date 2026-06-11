# BTCPay Server 前端配置界面说明

## 管理界面配置

### 1. 支付方式设置路径

```
管理后台 → 支付设置 → 支付方式 → 添加支付方式
```

### 2. 配置表单字段说明

#### 基础信息
- **支付方式名称**: 用户在选择支付时看到的名称
  - 建议：`加密货币支付`、`数字货币`、`BTC支付` 等
- **支付方式图标**: 显示的图标URL（可选）
  - 建议使用比特币或加密货币相关图标

#### 支付接口配置
- **支付接口**: 从下拉列表选择 `BTCPay`

#### 参数配置

| 字段名 | 标签 | 说明 | 示例值 |
|--------|------|------|--------|
| `btcpay_url` | API接口所在网址 | BTCPay Server地址，必须以/结尾 | `https://btcpay.example.com/` |
| `btcpay_storeId` | Store ID | BTCPay商店ID | `ABC123DEF456` |
| `btcpay_api_key` | Greenfield API 密钥 | BTCPay API访问密钥 | `1234567890abcdef...` |
| `btcpay_webhook_key` | WEBHOOK SECRET | Webhook验证密钥 | `your-random-secret` |

#### 费用设置
- **固定手续费**: 每笔交易固定费用（单位：分）
  - 例如：输入 `500` 表示 5 元
- **百分比手续费**: 按交易金额百分比收费
  - 例如：输入 `2.5` 表示 2.5%

#### 高级设置
- **通知域名**: 自定义支付通知接收域名（可选）
  - 用于CDN或反向代理场景

### 3. 配置步骤

#### 第一步：创建支付方式
1. 点击"添加支付方式"
2. 填写支付方式名称和图标
3. 选择支付接口为"BTCPay"

#### 第二步：配置基础参数
1. 填写BTCPay Server地址
2. 输入Store ID
3. 配置API密钥
4. 暂时跳过Webhook Secret

#### 第三步：保存并获取通知URL
1. 点击保存
2. 保存后会生成UUID，复制完整的通知URL
3. 格式：`https://yoursite.com/api/v1/guest/payment/notify/BTCPay/{UUID}`

#### 第四步：配置BTCPay Webhook
1. 在BTCPay Server中配置Webhook
2. 使用第三步获取的通知URL
3. 生成并设置Webhook Secret

#### 第五步：完善配置
1. 返回Xboard配置页面
2. 填写Webhook Secret
3. 配置手续费（如需要）
4. 保存配置

### 4. 配置验证

#### 前端显示检查
- [ ] 支付方式在前台正常显示
- [ ] 支付方式图标显示正确
- [ ] 支付方式名称显示正确

#### 支付流程测试
- [ ] 能够选择BTCPay支付方式
- [ ] 点击支付能正常跳转到BTCPay页面
- [ ] BTCPay页面显示正确的金额和货币
- [ ] 支付完成后能正确回调

#### 通知测试
- [ ] 支付成功后订单状态正确更新
- [ ] 后台日志记录正常
- [ ] 没有重复支付问题

### 5. 故障排除

#### 常见问题

**问题1：保存配置时提示"配置参数不能为空"**
- 检查所有必填字段是否已填写
- 确认API地址格式正确（以/结尾）

**问题2：支付时跳转失败**
- 检查BTCPay Server是否可访问
- 验证Store ID是否正确
- 确认API密钥权限是否足够

**问题3：支付完成但订单状态未更新**
- 检查Webhook URL是否正确配置
- 验证Webhook Secret是否一致
- 查看服务器日志中的错误信息

**问题4：重复支付问题**
- 检查订单状态判断逻辑
- 确认支付通知处理是否幂等

#### 日志检查

支付相关日志位置：
```
storage/logs/laravel.log
```

关键日志搜索：
```bash
# 支付通知日志
grep "Payment notification" storage/logs/laravel.log

# BTCPay相关日志
grep "BTCPay" storage/logs/laravel.log

# 错误日志
grep "ERROR" storage/logs/laravel.log | grep -i btcpay
```

### 6. 最佳实践

#### 安全建议
1. **API密钥安全**
   - 定期轮换API密钥
   - 仅授予必要的权限
   - 避免在前端显示密钥

2. **Webhook安全**
   - 使用强随机Webhook Secret
   - 验证请求来源IP
   - 启用HTTPS

3. **服务器安全**
   - 配置防火墙规则
   - 监控异常支付行为
   - 定期备份配置

#### 性能优化
1. **缓存设置**
   - 缓存BTCPay Server状态
   - 优化支付页面加载速度

2. **监控告警**
   - 设置支付失败率告警
   - 监控BTCPay Server连通性
   - 追踪支付转化率

### 7. 用户体验

#### 支付页面优化
- 提供清晰的支付说明
- 显示支持的加密货币类型
- 提供支付状态实时更新

#### 移动端适配
- 确保BTCPay支付页面在移动设备上正常显示
- 优化二维码扫描体验
- 提供钱包应用快速调用

#### 多语言支持
- 配置BTCPay Server多语言界面
- 提供本地化的支付说明
- 适配不同地区的用户习惯
