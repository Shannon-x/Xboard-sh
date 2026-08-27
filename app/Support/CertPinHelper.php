<?php

namespace App\Support;

/**
 * 自签证书生成与指纹计算。
 *
 * 存在的理由：当节点的 SNI 是伪装域名（比如 www.bing.com）时，
 * 任何真实证书都无法通过客户端验证，而 xray-core 已经移除了 allowInsecure
 * —— 配了会直接报错，官方指定的替代品是 pinnedPeerCertSha256。
 *
 * 因此改由面板持有证书：面板生成一张长效自签证书，把证书+私钥下发给节点，
 * 把指纹写进订阅，客户端靠指纹固定完成验证。节点侧只负责落盘
 * （对应 V2bX 的 CertMode=remote / v2node 的同名模式）。
 *
 * 做法参考 wyx2685/v2board 的 V2nodeController，
 * 区别是这里同时算出两种指纹，因为各客户端固定的对象不一样。
 */
class CertPinHelper
{
    /**
     * 生成一张自签证书，并返回证书、私钥与两种指纹。
     *
     * @param  string  $commonName  证书的 CN/SAN，通常就是节点的伪装 SNI
     * @param  int     $days        有效期天数
     * @return array{cert:string,key:string,cert_sha256:string,pubkey_sha256:string}
     *
     * @throws \RuntimeException 任一 openssl 步骤失败时抛出
     */
    public static function generate(string $commonName, int $days = 3650): array
    {
        if ($commonName === '') {
            throw new \RuntimeException('证书 CN 不能为空');
        }

        // 与 v2board 一致用 EC prime256v1：比 RSA 快、证书更小，
        // 对 QUIC 握手尤其友好。
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new \RuntimeException('生成私钥失败: ' . openssl_error_string());
        }

        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new \RuntimeException('生成 CSR 失败: ' . openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new \RuntimeException('签发证书失败: ' . openssl_error_string());
        }

        if (!openssl_pkey_export($key, $keyPem)) {
            throw new \RuntimeException('导出私钥失败: ' . openssl_error_string());
        }
        if (!openssl_x509_export($cert, $certPem)) {
            throw new \RuntimeException('导出证书失败: ' . openssl_error_string());
        }

        return [
            'cert' => $certPem,
            'key' => $keyPem,
            'cert_sha256' => self::certSha256($certPem),
            'pubkey_sha256' => self::publicKeySha256($certPem),
        ];
    }

    /**
     * 证书 DER 的 SHA256（小写 hex）。
     *
     * 对应 xray 的 pinnedPeerCertSha256(pcs) 与 hysteria 官方客户端的
     * tls.pinSHA256 —— 这两个用的是同一个值。
     */
    public static function certSha256(string $certPem): string
    {
        $der = self::pemToDer($certPem);
        return hash('sha256', $der);
    }

    /**
     * 公钥 SPKI 的 SHA256（小写 hex）。
     *
     * 对应 sing-box 的 certificate_public_key_sha256。
     * 注意这与 certSha256 是**两个不同的值**，填错客户端会直接连不上。
     */
    public static function publicKeySha256(string $certPem): string
    {
        $cert = openssl_x509_read($certPem);
        if ($cert === false) {
            throw new \RuntimeException('读取证书失败: ' . openssl_error_string());
        }
        $pubKey = openssl_pkey_get_public($cert);
        if ($pubKey === false) {
            throw new \RuntimeException('读取公钥失败: ' . openssl_error_string());
        }
        $details = openssl_pkey_get_details($pubKey);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException('导出公钥失败: ' . openssl_error_string());
        }
        // openssl_pkey_get_details 返回的是 SubjectPublicKeyInfo 的 PEM，
        // 去掉头尾与换行后就是 sing-box 要哈希的 DER。
        return hash('sha256', self::pemToDer($details['key'], 'PUBLIC KEY'));
    }

    /**
     * 把 PEM 转成原始 DER 字节。
     */
    private static function pemToDer(string $pem, string $label = 'CERTIFICATE'): string
    {
        $stripped = preg_replace(
            '/-----BEGIN ' . preg_quote($label, '/') . '-----|-----END ' . preg_quote($label, '/') . '-----|\s+/',
            '',
            $pem
        );
        $der = base64_decode((string) $stripped, true);
        if ($der === false) {
            throw new \RuntimeException('PEM 解码失败');
        }
        return $der;
    }
}
