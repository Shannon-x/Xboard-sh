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

        $servers = $servers->map(function ($item) use ($groupMap, $parentMap) {
            $item['groups'] = collect($item['group_ids'] ?? [])
                ->map(fn ($gid) => $groupMap->get($gid))
                ->filter()
                ->values();
            $item['parent'] = $item->parent_id ? $parentMap->get($item->parent_id) : null;
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

        $oldServer = $request->input('id') ? Server::find($request->input('id')) : null;
        $this->normalizeEchPayload($params, $oldServer);
        $this->normalizeRemoteCert($params, $oldServer);

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

        $old = $oldServer?->cert_config ?? [];

        // 已经有证书就沿用，不重新生成。
        foreach (['tls_cert', 'tls_key', 'pinned_peer_cert_sha256', 'pinned_public_key_sha256'] as $key) {
            if (empty($params['cert_config'][$key]) && !empty($old[$key])) {
                $params['cert_config'][$key] = $old[$key];
            }
        }
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
        if (!isset($params['protocol_settings']) || !is_array($params['protocol_settings'])) {
            $params['protocol_settings'] = [];
        }
        // hysteria 用 tls.*，其余协议用 tls_settings.*，两处都写。
        foreach (['tls', 'tls_settings'] as $key) {
            if (!isset($params['protocol_settings'][$key]) || !is_array($params['protocol_settings'][$key])) {
                $params['protocol_settings'][$key] = [];
            }
            if (!empty($certPin)) {
                $params['protocol_settings'][$key]['pinned_peer_cert_sha256'] = $certPin;
            }
            if (!empty($pubPin)) {
                $params['protocol_settings'][$key]['pinned_public_key_sha256'] = $pubPin;
            }
        }
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
