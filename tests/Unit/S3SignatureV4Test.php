<?php

namespace Tests\Unit;

use App\Services\TicketAttachment\Storage\S3SignatureV4;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 用 AWS 官方文档《Signature Calculations for the Authorization Header》与
 * 《Authenticating Requests: Using Query Parameters》给出的示例向量锁定 SigV4 实现。
 * 两组向量的密钥、时间、桶名都是文档里的公开示例值，签名结果与文档逐字一致。
 */
class S3SignatureV4Test extends TestCase
{
    private const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private function signer(): S3SignatureV4
    {
        return new S3SignatureV4(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2013-05-24T00:00:00Z');
    }

    public function test_header_signature_matches_aws_get_object_example(): void
    {
        $headers = $this->signer()->signHeaders(
            'GET',
            'https://examplebucket.s3.amazonaws.com/test.txt',
            ['Range' => 'bytes=0-9'],
            S3SignatureV4::EMPTY_PAYLOAD_HASH,
            $this->now()
        );

        $this->assertSame('20130524T000000Z', $headers['x-amz-date']);
        $this->assertSame('examplebucket.s3.amazonaws.com', $headers['host']);
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['authorization']
        );
    }

    public function test_presigned_url_matches_aws_query_parameter_example(): void
    {
        $url = $this->signer()->presign(
            'GET',
            'https://examplebucket.s3.amazonaws.com/test.txt',
            86400,
            [],
            $this->now()
        );

        $this->assertStringStartsWith('https://examplebucket.s3.amazonaws.com/test.txt?', $url);
        $this->assertStringContainsString('X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request', $url);
        $this->assertStringContainsString('X-Amz-Expires=86400', $url);
        $this->assertStringEndsWith(
            '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $url
        );
    }

    public function test_host_header_keeps_non_default_port_and_encodes_path_once(): void
    {
        $headers = $this->signer()->signHeaders(
            'PUT',
            'http://minio.local:9000/bucket/ticket-attachments/2026/09/a b.png',
            ['content-type' => 'image/png'],
            S3SignatureV4::EMPTY_PAYLOAD_HASH,
            $this->now()
        );
        $this->assertSame('minio.local:9000', $headers['host']);
        $this->assertSame('content-type;host;x-amz-content-sha256;x-amz-date', $this->signedHeadersOf($headers['authorization']));

        $this->assertSame('/bucket/a%20b/c%2Fd', S3SignatureV4::encodePath('/bucket/a b/c%2Fd'));
    }

    private function signedHeadersOf(string $authorization): string
    {
        preg_match('/SignedHeaders=([^,]+)/', $authorization, $m);
        return $m[1] ?? '';
    }
}
