<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ServerRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RouteController extends Controller
{
    public function fetch(Request $request)
    {
        $routes = ServerRoute::get();
        return [
            'data' => $routes
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'remarks' => 'required',
            'match' => 'array|required_unless:action,default_out',
            'action' => 'required|in:block,block_ip,block_port,protocol,dns,route,route_ip,default_out,direct,proxy',
            'action_value' => 'nullable'
        ], [
            'remarks.required' => '备注不能为空',
            'match.required_unless' => '匹配值不能为空',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误'
        ]);

        if (($params['action'] ?? '') === 'default_out') {
            $params['match'] = [];
        } else {
            $params['match'] = array_filter((array)($params['match'] ?? []));
        }
        // TODO: remove on 1.8.0
        if ($request->input('id')) {
            try {
                $route = ServerRoute::find($request->input('id'));
                $route->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500,'保存失败']);
            }
        }
        try{
            ServerRoute::create($params);
            return $this->success(true);
        }catch(\Exception $e){
            Log::error($e);
            return $this->fail([500,'创建失败']);
        }
    }

    public function drop(Request $request)
    {
        $route = ServerRoute::find($request->input('id'));
        if (!$route) throw new ApiException('路由不存在');
        if (!$route->delete()) throw new ApiException('删除失败');
        return [
            'data' => true
        ];
    }
}
