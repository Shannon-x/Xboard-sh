<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;

class TestBTCPay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'btcpay:test {--payment-id= : BTCPay支付方式ID} {--test-invoice : 测试创建发票} {--check-config : 检查配置}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'BTCPay Server 配置测试工具';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $paymentId = $this->option('payment-id');
        
        if (!$paymentId) {
            $this->showAvailablePayments();
            return 0;
        }

        $payment = Payment::find($paymentId);
        if (!$payment || $payment->payment !== 'BTCPay') {
            $this->error('指定的支付方式不存在或不是BTCPay类型');
            return 1;
        }

        if ($this->option('check-config')) {
            return $this->checkConfig($payment);
        }

        if ($this->option('test-invoice')) {
            return $this->testCreateInvoice($payment);
        }

        $this->info('请指定测试选项：');
        $this->info('  --check-config   检查配置连通性');
        $this->info('  --test-invoice   测试创建发票');
        
        return 0;
    }

    private function showAvailablePayments()
    {
        $btcpayPayments = Payment::where('payment', 'BTCPay')->get();
        
        if ($btcpayPayments->isEmpty()) {
            $this->info('没有找到BTCPay支付方式');
            return;
        }

        $this->info('可用的BTCPay支付方式：');
        $this->table(['ID', '名称', '状态', 'Store ID'], $btcpayPayments->map(function ($payment) {
            return [
                $payment->id,
                $payment->name,
                $payment->enable ? '启用' : '禁用',
                $payment->config['btcpay_storeId'] ?? 'N/A'
            ];
        })->toArray());
        
        $this->info('');
        $this->info('使用方法：');
        $this->info('php artisan btcpay:test --payment-id=<ID> --check-config');
        $this->info('php artisan btcpay:test --payment-id=<ID> --test-invoice');
    }

    private function checkConfig(Payment $payment)
    {
        $this->info('检查BTCPay Server配置...');
        
        $config = $payment->config;
        $required = ['btcpay_url', 'btcpay_storeId', 'btcpay_api_key'];
        
        foreach ($required as $key) {
            if (empty($config[$key])) {
                $this->error("缺少必要配置：{$key}");
                return 1;
            }
        }

        $this->info('✓ 基础配置完整');

        // 测试API连接
        $url = rtrim($config['btcpay_url'], '/') . '/api/v1/stores/' . $config['btcpay_storeId'];
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    "Authorization: token " . $config['btcpay_api_key'],
                    "Content-Type: application/json"
                ],
                'timeout' => 10
            ]
        ]);

        $this->info('测试API连接...');
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $this->error('✗ 无法连接到BTCPay Server');
            $this->error('请检查：');
            $this->error('1. API地址是否正确');
            $this->error('2. 网络连接是否正常');
            $this->error('3. BTCPay Server是否运行正常');
            return 1;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('✗ BTCPay Server返回无效JSON');
            return 1;
        }

        if (isset($data['error'])) {
            $this->error('✗ BTCPay Server错误：' . ($data['error']['message'] ?? '未知错误'));
            if ($data['error']['code'] ?? null === 'store-not-found') {
                $this->error('Store ID不存在，请检查Store ID配置');
            }
            return 1;
        }

        $this->info('✓ API连接成功');
        
        if (isset($data['name'])) {
            $this->info("商店名称：{$data['name']}");
        }
        
        if (isset($data['defaultCurrency'])) {
            $this->info("默认币种：{$data['defaultCurrency']}");
        }

        // 检查Webhook配置
        if (!empty($config['btcpay_webhook_key'])) {
            $this->info('✓ Webhook密钥已配置');
        } else {
            $this->warn('⚠ 未配置Webhook密钥，支付通知可能不安全');
        }

        $this->info('✓ 配置检查完成');
        return 0;
    }

    private function testCreateInvoice(Payment $payment)
    {
        $this->info('测试创建BTCPay发票...');

        try {
            $paymentService = new PaymentService('BTCPay', $payment->id);
            
            $testOrder = [
                'trade_no' => 'TEST_' . time(),
                'total_amount' => 100, // 1元测试订单
                'user_id' => 1,
                'return_url' => url('/#/test'),
                'notify_url' => url("/api/v1/guest/payment/notify/BTCPay/{$payment->uuid}")
            ];

            $result = $paymentService->pay($testOrder);

            if (isset($result['data']) && filter_var($result['data'], FILTER_VALIDATE_URL)) {
                $this->info('✓ 测试发票创建成功');
                $this->info("支付链接：{$result['data']}");
                $this->info('');
                $this->warn('注意：这是一个真实的测试发票，请不要进行实际支付');
                $this->info('您可以访问上述链接查看支付页面是否正常显示');
            } else {
                $this->error('✗ 发票创建失败');
                $this->error('返回数据：' . json_encode($result, JSON_UNESCAPED_UNICODE));
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('✗ 发票创建失败');
            $this->error('错误信息：' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
