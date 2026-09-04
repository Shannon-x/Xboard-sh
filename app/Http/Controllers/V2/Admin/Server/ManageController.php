<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Support\CertPinHelper;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers();

        // 一次性预取所有用到的 ServerGroup，避免 N+1（每个 server 一次 whereIn 查询）
        $allGroupIds = $servers->pluck('group_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
        $groupMap = $allGroupIds
            ? ServerGroup::whereIn('id', $allGroupIds)->get(['id', 'name'])->keyBy('id')
            : collect();

        // 同样预取 parent，避免每个子节点单独查 parent_id
        $parentIds = $servers->pluck('parent_id')->filter()->unique()->values()->all();
        $parentMap = $parentIds
            ? Server::whereIn('id', $parentIds)->get()->keyBy('id')
            : collect();

        // 管理列表不需要证书/私钥本体（remote 模式的 tls_key 是服务端私钥），
        // 以布尔占位键 tls_key_set / tls_cert_set 告知“已配置”。
        // 只改内存模型——本方法内的模型（含 parentMap 中共享的 parent）绝不允许 save()，
        // 否则脱敏后的 cert_config 会写回库，等价于清掉私钥。
        $sanitizeCert = static function ($cert) {
            if (!is_array($cert)) {
                return $cert;
            }
            if (!empty($cert['tls_key'])) {
                $cert['tls_key_set'] = true;
            }
            if (!empty($cert['tls_cert'])) {
                $cert['tls_cert_set'] = true;
            }
            unset($cert['tls_key'], $cert['tls_cert']);
            return $cert;
        };
        foreach ($parentMap as $parent) {
            $parent->setAttribute('cert_config', $sanitizeCert($parent->cert_config));
        }

        $servers = $servers->map(function ($item) use ($groupMap, $parentMap, $sanitizeCert) {
            $item['groups'] = collect($item['group_ids'] ?? [])
                ->map(fn ($gid) => $groupMap->get($gid))
                ->filter()
                ->values();
            $item['parent'] = $item->parent_id ? $parentMap->get($item->parent_id) : null;
            $item->setAttribute('cert_config', $sanitizeCert($item->cert_config));
            return $item;
        });
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();

        $rawContent = json_decode($request->getContent(), false);
        if (isset($rawContent->protocol_settings->network_settings) && is_object($rawContent->protocol_settings->network_settings)) {
            $params['protocol_settings']['network_settings'] = (array) $rawContent->protocol_settings->network_settings;
        }

        // 不变量的属主在后端：null / 非数组 / 空 cert_mode 的 cert_config 一律视为
        // "未管理该键"（旧版前端对多数协议显式发 cert_config:null，照单全收会把
        // 库里的证书/私钥/双指纹整列清掉）。主动清空走 cert_mode:'none'，轮换走显式清库。
        if (
            array_key_exists('cert_config', $params)
            && (
                !is_array($params['cert_config'])
                || trim((string) data_get($params['cert_config'], 'cert_mode', '')) === ''
            )
        ) {
            unset($params['cert_config']);
        }

        if (isset($params['cert_config']) && is_array($params['cert_config'])) {
            // getNodes 脱敏用的占位键，不得持久化进 cert_config
            unset($params['cert_config']['tls_key_set'], $params['cert_config']['tls_cert_set']);
        }

        $oldServer = $request->input('id') ? Server::find($request->input('id')) : null;
        $this->normalizeEchPayload($params, $oldServer);
        $this->preserveServerHeldCert($params, $oldServer);
        $this->normalizeRemoteCert($params, $oldServer);
        $this->resyncCertPinFromOldServer($params, $oldServer);

        if ($request->input('id')) {
            $server = $oldServer ?: Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }


    }

    /**
     * 面板持有的证书材料（证书/私钥/两个指纹）对所有模式的保存做保留合并：
     * 前端表单从不回传这些键（列表接口也已脱敏），提交值为空即按旧值回填。
     * 效果：remote→http→remote 的模式往返不丢证书、不换指纹
     * （换指纹 = 已发订阅全部失效）。主动轮换证书需显式清库。
     */
    private function preserveServerHeldCert(array &$params, ?Server $oldServer): void
    {
        if (!isset($params['cert_config']) || !is_array($params['cert_config'])) {
            return;
        }
        $old = $oldServer?->cert_config ?? [];
        foreach (['tls_cert', 'tls_key', 'pinned_peer_cert_sha256', 'pinned_public_key_sha256'] as $key) {
            if (empty($params['cert_config'][$key]) && !empty($old[$key])) {
                $params['cert_config'][$key] = $old[$key];
            }
        }
    }

    /**
     * 前端只对部分协议提交 cert_config；remote 节点经其它协议表单编辑时
     * cert_config 整键缺省（库列被保留），但 protocol_settings 仍会被模型按
     * 白名单重建——订阅指纹必须从旧记录回写，否则订阅重新生成后
     * pcs/certificate_public_key_sha256 消失，客户端对自签证书校验必败。
     */
    private function resyncCertPinFromOldServer(array &$params, ?Server $oldServer): void
    {
        if (
            isset($params['cert_config'])
            || !isset($params['protocol_settings'])
            || !is_array($params['protocol_settings'])
        ) {
            return;
        }
        $oldCert = $oldServer?->cert_config ?? null;
        // 仅限节点确实在用面板证书（remote）时回写：模式已切走的节点，
        // cert_config 里由 preserveServerHeldCert 留存的历史指纹不能再钉进订阅
        if (!is_array($oldCert) || data_get($oldCert, 'cert_mode') !== 'remote') {
            return;
        }
        // 借 syncCertPinToProtocolSettings 的协议落点映射与非数组守卫；
        // cert_config 只临时挂载，不写回 $params（该键保持缺省=库列不动）
        $withCert = $params;
        $withCert['cert_config'] = $oldCert;
        $this->syncCertPinToProtocolSettings($withCert);
        $params['protocol_settings'] = $withCert['protocol_settings'];
    }

    /**
     * remote 证书模式：由面板生成自签证书并算出指纹。
     *
     * 用于节点 SNI 是伪装域名的场景 —— 这时任何真实证书都无法通过客户端验证，
     * 而 xray-core 已经移除 allowInsecure（配了直接报错，官方指定改用
     * pinnedPeerCertSha256）。于是改由面板持有证书：
     *
     *   面板生成证书 -> 下发 tls_cert/tls_key 给节点 -> 指纹写进订阅
     *
     * 节点侧只负责落盘（V2bX 的 CertMode=remote / v2node 同名模式）。
     * 做法参考 wyx2685/v2board 的 V2nodeController。
     *
     * 证书只在缺失时生成一次并沿用，避免每次保存节点都换指纹
     * —— 换了指纹等于让所有已下发的订阅全部失效。
     */
    private function normalizeRemoteCert(array &$params, ?Server $oldServer): void
    {
        if (data_get($params, 'cert_config.cert_mode') !== 'remote') {
            return;
        }

        // 旧值回填已由 preserveServerHeldCert 在此之前完成（所有模式生效）。
        if (!empty($params['cert_config']['tls_cert']) && !empty($params['cert_config']['tls_key'])) {
            $this->syncCertPinToProtocolSettings($params);
            return;
        }

        // CN 用节点的 SNI；伪装域名同样可以签，反正客户端是靠指纹验证的。
        $cn = data_get($params, 'protocol_settings.tls.server_name')
            ?: data_get($params, 'protocol_settings.tls_settings.server_name')
            ?: data_get($params, 'cert_config.server_name')
            ?: data_get($params, 'host')
            ?: 'example.com';

        try {
            $generated = CertPinHelper::generate((string) $cn);
        } catch (\Throwable $e) {
            Log::error('生成 remote 证书失败: ' . $e->getMessage());
            return;
        }

        $params['cert_config']['tls_cert'] = $generated['cert'];
        $params['cert_config']['tls_key'] = $generated['key'];
        $params['cert_config']['pinned_peer_cert_sha256'] = $generated['cert_sha256'];
        $params['cert_config']['pinned_public_key_sha256'] = $generated['pubkey_sha256'];

        $this->syncCertPinToProtocolSettings($params);
    }

    /**
     * 指纹按协议 schema 写入对应落点：
     *   tls 是对象(TLS_CONFIGURATION)的协议 -> tls.*
     *   tls 是 0/1/2 整数枚举的协议        -> tls_settings.*
     * 绝不能对后者写 tls 数组 —— 模型 cast 会把数组 (int) 强转成 1，
     * REALITY(2)/关闭(0) 的原值被不可逆钳死。
     */
    private const CERT_PIN_TARGET_KEY = [
        Server::TYPE_HYSTERIA => 'tls',
        Server::TYPE_TUIC => 'tls',
        Server::TYPE_ANYTLS => 'tls',
        Server::TYPE_TROJAN => 'tls_settings',
        Server::TYPE_VMESS => 'tls_settings',
        Server::TYPE_VLESS => 'tls_settings',
        Server::TYPE_SOCKS => 'tls_settings',
        Server::TYPE_NAIVE => 'tls_settings',
        Server::TYPE_HTTP => 'tls_settings',
        // shadowsocks / mieru 无 TLS 字段：不写指纹
    ];

    /**
     * 把指纹同步进 protocol_settings，订阅生成时从这里读。
     *
     * 两个值必须分开存，因为各客户端固定的对象不同：
     *   pinned_peer_cert_sha256   证书 DER 哈希 -> xray pcs / hysteria pinSHA256
     *   pinned_public_key_sha256  公钥 SPKI 哈希 -> sing-box certificate_public_key_sha256
     * 填错客户端会直接连不上。
     */
    private function syncCertPinToProtocolSettings(array &$params): void
    {
        $certPin = data_get($params, 'cert_config.pinned_peer_cert_sha256');
        $pubPin = data_get($params, 'cert_config.pinned_public_key_sha256');
        if (empty($certPin) && empty($pubPin)) {
            return;
        }
        $type = Server::normalizeType($params['type'] ?? null);
        $key = self::CERT_PIN_TARGET_KEY[$type] ?? null;
        if ($key === null) {
            return;
        }
        if (!isset($params['protocol_settings']) || !is_array($params['protocol_settings'])) {
            $params['protocol_settings'] = [];
        }
        // 目标键已存在且不是数组（如 REALITY 模式下 tls=2）时宁可不写指纹，也绝不覆盖原值。
        if (isset($params['protocol_settings'][$key]) && !is_array($params['protocol_settings'][$key])) {
            Log::warning("cert pin not synced: protocol_settings.{$key} is not an array", [
                'server_id' => $params['id'] ?? null,
                'type' => $type,
            ]);
            return;
        }
        if (!isset($params['protocol_settings'][$key])) {
            $params['protocol_settings'][$key] = [];
        }
        if (!empty($certPin)) {
            $params['protocol_settings'][$key]['pinned_peer_cert_sha256'] = $certPin;
        }
        if (!empty($pubPin)) {
            $params['protocol_settings'][$key]['pinned_public_key_sha256'] = $pubPin;
        }

        // 这里刻意**不**动 allow_insecure。
        //
        // 「有指纹是否还要跳过链校验」是按内核而异的，不能在这里一刀切：
        //   · hysteria 原生内核：pin 挂在 VerifyPeerCertificate 上，而 Go 的链校验失败会直接
        //     中断握手、回调根本不执行，所以自签证书下必须 insecure=1 配合（由 General.php 负责）
        //   · Shadowrocket / mihomo / sing-box 1.13+ / 新版 Xray：pin **替代**链校验，
        //     此时再强开 insecure 反而可能让客户端跳过指纹校验，把站长开的安全特性废掉
        // 所以下发策略交给各订阅生成器按目标客户端决定，见 docs/hysteria-cert-pin.md。
    }

    private function normalizeEchPayload(array &$params, ?Server $oldServer): void
    {
        if (!isset($params['protocol_settings']) || !is_array($params['protocol_settings'])) {
            return;
        }

        $oldSettings = $oldServer?->protocol_settings ?? [];

        foreach (['tls_settings', 'tls'] as $tlsKey) {
            if (
                !isset($params['protocol_settings'][$tlsKey]) ||
                !is_array($params['protocol_settings'][$tlsKey]) ||
                !array_key_exists('ech', $params['protocol_settings'][$tlsKey])
            ) {
                continue;
            }

            $oldEch = data_get($oldSettings, "{$tlsKey}.ech")
                ?: data_get($oldSettings, 'tls_settings.ech')
                ?: data_get($oldSettings, 'tls.ech');

            $params['protocol_settings'][$tlsKey]['ech'] = $this->normalizeSingleEch(
                $params['protocol_settings'][$tlsKey]['ech'],
                is_array($oldEch) ? $oldEch : null
            );
        }
    }

    private function normalizeSingleEch($ech, ?array $oldEch): ?array
    {
        if ($ech === null || $ech === false || !is_array($ech)) {
            return null;
        }

        if (array_key_exists('enabled', $ech) && !$this->toBool($ech['enabled'])) {
            return null;
        }

        $type = trim((string) ($ech['type'] ?? data_get($oldEch, 'type', '')));
        if ($type === '' && $this->hasAnyEchValue($ech)) {
            $type = $this->hasAnyEchValue($oldEch) ? (string) data_get($oldEch, 'type', 'custom') : 'custom';
        }
        if ($type === '') {
            $type = 'cloudflare';
        }

        if ($type === 'cloudflare') {
            return [
                'enabled' => true,
                'type' => 'cloudflare',
                'config' => 'cloudflare-ech.com+https://doh.pub/dns-query',
                'query_server_name' => null,
                'key' => null,
                'key_path' => null,
                'config_path' => null,
            ];
        }

        if ($type !== 'custom') {
            return null;
        }

        $queryServerName = $this->trimToNull($ech['query_server_name'] ?? data_get($oldEch, 'query_server_name'));
        $oldQueryServerName = $this->trimToNull(data_get($oldEch, 'query_server_name'));
        $queryChanged = $oldQueryServerName && $queryServerName && $oldQueryServerName !== $queryServerName;

        $config = $queryChanged ? null : $this->trimToNull($ech['config'] ?? data_get($oldEch, 'config'));
        $key = $queryChanged ? null : $this->trimToNull($ech['key'] ?? data_get($oldEch, 'key'));

        if ($queryServerName && (!$config || !$key)) {
            $echPair = Helper::generateEchKeyPair($queryServerName);
            $key = $echPair['ech_key'];
            $config = $echPair['ech_config'];
        }

        return [
            'enabled' => true,
            'type' => 'custom',
            'config' => $config,
            'query_server_name' => $queryServerName,
            'key' => $key,
            'key_path' => $this->trimToNull($ech['key_path'] ?? data_get($oldEch, 'key_path')),
            'config_path' => $this->trimToNull($ech['config_path'] ?? data_get($oldEch, 'config_path')),
        ];
    }

    private function hasAnyEchValue(?array $ech): bool
    {
        if (!$ech) {
            return false;
        }

        foreach (['config', 'query_server_name', 'key', 'key_path', 'config_path'] as $field) {
            if ($this->trimToNull($ech[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function toBool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function trimToNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'show' => 'integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        $server->show = (int) $request->show;
        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        if (Server::where('id', $request->id)->delete() === false) {
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }


    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        $copiedServer->code = null;
        $copiedServer->save();

        return $this->success(true);
    }
}
