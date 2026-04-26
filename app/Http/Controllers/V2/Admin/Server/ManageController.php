<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
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
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'])->get(['name', 'id']);
            $item['parent'] = $item->parent;
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
        $oldServer = $request->input('id') ? Server::find($request->input('id')) : null;
        $this->normalizeEchPayload($params, $oldServer);

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
