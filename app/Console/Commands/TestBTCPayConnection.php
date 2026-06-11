<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;

class TestBTCPayConnection extends Command
{
    protected $signature = 'btcpay:test {payment_id}';
    protected $description = 'Test BTCPay Server connection and configuration';

    public function handle()
    {
        $paymentId = $this->argument('payment_id');
        
        $payment = Payment::find($paymentId);
        if (!$payment || $payment->payment !== 'BTCPay') {
            $this->error('BTCPay payment method not found with ID: ' . $paymentId);
            return 1;
        }

        $this->info('Testing BTCPay Server connection...');
        $this->info('Payment Method: ' . $payment->name);
        
        $config = $payment->config;
        
        // 验证配置完整性
        $this->line('Checking configuration...');
        $requiredFields = ['btcpay_url', 'btcpay_storeId', 'btcpay_api_key'];
        $missing = [];
        
        foreach ($requiredFields as $field) {
            if (empty($config[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            $this->error('Missing required configuration fields: ' . implode(', ', $missing));
            return 1;
        }
        
        $this->info('✓ Configuration complete');
        
        // 测试API连接
        $this->line('Testing API connection...');
        
        try {
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

            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                $this->error('✗ Failed to connect to BTCPay Server');
                return 1;
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['error'])) {
                $this->error('✗ BTCPay Server error: ' . $data['error']['message']);
                return 1;
            }
            
            $this->info('✓ API connection successful');
            $this->info('Store Name: ' . ($data['name'] ?? 'N/A'));
            $this->info('Store ID: ' . $data['id']);
            
        } catch (\Exception $e) {
            $this->error('✗ Connection test failed: ' . $e->getMessage());
            return 1;
        }
        
        // 显示通知URL
        $this->line('');
        $this->info('Webhook Configuration:');
        $notifyUrl = url("/api/v1/guest/payment/notify/BTCPay/{$payment->uuid}");
        if ($payment->notify_domain) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $payment->notify_domain . $parseUrl['path'];
        }
        
        $this->line('Notification URL: ' . $notifyUrl);
        
        if (empty($config['btcpay_webhook_key'])) {
            $this->warn('⚠ WEBHOOK SECRET not configured - notifications will not be verified');
        } else {
            $this->info('✓ WEBHOOK SECRET configured');
        }
        
        $this->line('');
        $this->info('BTCPay Server test completed successfully!');
        
        return 0;
    }
}
