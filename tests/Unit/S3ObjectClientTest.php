<?php

namespace Tests\Unit;

use App\Services\ObjectStorage\S3ObjectClient;
use App\Services\TicketAttachment\Storage\S3AttachmentStorage;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * S3 最小客户端：URL 形态、签名头、状态码判定；工单附件驱动只是它的薄封装。
 */
class S3ObjectClientTest extends TestCase
{
    private const CFG = [
        'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'xb',
        'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'secret', 'path_style' => true,
    ];

    private MockHandler $mock;
    private array $history = [];

    private function http(): Client
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    public function test_object_urls_follow_path_or_virtual_host_style_and_encode_keys(): void
    {
        $path = new S3ObjectClient(self::CFG, $this->http());
        $this->assertSame('https://acct.r2.cloudflarestorage.com/xb/billing/documents/7/RC-1.pdf', $path->objectUrl('billing/documents/7/RC-1.pdf'));
        $this->assertSame('https://acct.r2.cloudflarestorage.com/xb/a%20b/%E6%94%B6%E6%8D%AE.pdf', $path->objectUrl('a b/收据.pdf'));

        $virtual = new S3ObjectClient(['path_style' => false, 'endpoint' => 'https://s3.example.test:9443'] + self::CFG, $this->http());
        $this->assertSame('https://xb.s3.example.test:9443/k/1.pdf', $virtual->objectUrl('k/1.pdf'));

        $aws = new S3ObjectClient(['endpoint' => '', 'region' => 'ap-northeast-1'] + self::CFG, $this->http());
        $this->assertSame('https://s3.ap-northeast-1.amazonaws.com', $aws->endpoint());

        $this->expectException(RuntimeException::class);
        new S3ObjectClient(['bucket' => ''] + self::CFG, $this->http());
    }

    public function test_requests_are_signed_and_statuses_are_checked(): void
    {
        $client = new S3ObjectClient(self::CFG, $this->http());

        $this->mock->append(new Response(200));
        $client->putContents('k/1.pdf', '%PDF-1.4', 'application/pdf');
        $put = $this->history[0]['request'];
        $this->assertSame('PUT', $put->getMethod());
        $this->assertSame('application/pdf', $put->getHeaderLine('Content-Type'));
        $this->assertSame(hash('sha256', '%PDF-1.4'), $put->getHeaderLine('x-amz-content-sha256'));
        $this->assertMatchesRegularExpression('/^AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE\/\d{8}\/auto\/s3\/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=[a-f0-9]{64}$/', $put->getHeaderLine('Authorization'));

        $this->mock->append(new Response(200, [], 'body'));
        $this->assertSame('body', $client->get('k/1.pdf'));
        $this->mock->append(new Response(404));
        $this->assertNull($client->find('k/1.pdf'));
        $this->mock->append(new Response(200));
        $this->assertTrue($client->exists('k/1.pdf'));
        $this->mock->append(new Response(404));
        $this->assertFalse($client->exists('k/1.pdf'));
        $this->mock->append(new Response(404));
        $client->delete('k/1.pdf');   // 不存在视为成功

        $url = $client->presign('k/1.pdf', 600, ['response-content-type' => 'application/pdf']);
        $this->assertStringStartsWith('https://acct.r2.cloudflarestorage.com/xb/k/1.pdf?', $url);
        $this->assertStringContainsString('X-Amz-Expires=600', $url);
        $this->assertStringContainsString('response-content-type=application%2Fpdf', $url);
        $this->assertMatchesRegularExpression('/&X-Amz-Signature=[a-f0-9]{64}$/', $url);

        $this->mock->append(new Response(403, [], '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>'));
        try {
            $client->get('k/1.pdf');
            $this->fail('403 应抛异常');
        } catch (RuntimeException $e) {
            $this->assertSame('S3 GET 失败（HTTP 403） AccessDenied: Access Denied', $e->getMessage());
        }
    }

    public function test_attachment_storage_delegates_to_the_client(): void
    {
        $storage = new S3AttachmentStorage(['prefix' => 'tickets', 'public_url' => ''] + self::CFG, $this->http());
        $this->assertSame('s3', $storage->driver());
        $this->assertMatchesRegularExpression('#^tickets/\d{4}/\d{2}/[0-9a-f-]{36}\.png$#', $storage->newKey('png'));

        $tmp = tempnam(sys_get_temp_dir(), 'xb-test');
        file_put_contents($tmp, 'png-bytes');
        try {
            $this->mock->append(new Response(200));
            $storage->put('tickets/2026/10/a.png', $tmp, 'image/png');
        } finally {
            @unlink($tmp);
        }
        $put = $this->history[0]['request'];
        $this->assertSame('PUT', $put->getMethod());
        $this->assertSame('https://acct.r2.cloudflarestorage.com/xb/tickets/2026/10/a.png', (string) $put->getUri());
        $this->assertSame('image/png', $put->getHeaderLine('Content-Type'));
        $this->assertSame(hash('sha256', 'png-bytes'), $put->getHeaderLine('x-amz-content-sha256'));

        $url = $storage->temporaryUrl('tickets/2026/10/a.png', 600, '截图.png', true, 'image/png');
        // 文件名先 rawurlencode 进 disposition，再作为 query 值整体编码一次（S3 要求 query 值 RFC3986 编码）
        $this->assertStringContainsString("response-content-disposition=inline%3B%20filename%2A%3DUTF-8%27%27%25E6%2588%25AA%25E5%259B%25BE.png", $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);

        $this->history = [];
        $this->mock->append(new Response(200), new Response(200), new Response(204));
        $storage->probe();
        $this->assertSame(['PUT', 'HEAD', 'DELETE'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        $this->assertMatchesRegularExpression('#/xb/tickets/\d{4}/\d{2}/\.probe-[0-9a-f-]{36}\.txt$#', (string) $this->history[0]['request']->getUri());

        // 配了公开地址：下载直接跳公开 URL，不签名
        $public = new S3AttachmentStorage(['prefix' => 'tickets', 'public_url' => 'https://files.example.test/'] + self::CFG, $this->http());
        $this->assertSame('https://files.example.test/tickets/2026/10/a.png', $public->temporaryUrl('tickets/2026/10/a.png', 600, 'a.png', true, 'image/png'));
    }
}
