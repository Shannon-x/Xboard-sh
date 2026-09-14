<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Http\Requests\Admin\KnowledgeSort;
use App\Models\Knowledge;
use App\Services\KnowledgePublicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class KnowledgeController extends Controller
{
    public function __construct(private KnowledgePublicationService $publication) {}

    public function capabilities()
    {
        foreach (['visibility', 'slug', 'summary', 'published_at'] as $column) {
            abort_unless(Schema::hasColumn('v2_knowledge', $column), 503, '请先完成知识库权限迁移。');
        }
        return $this->success(['publication_version' => 1, 'visibilities' => Knowledge::VISIBILITIES,
            'public_enabled' => (bool) config('knowledge.public_enabled')]);
    }

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            // 先查模型再判空，避免 null->toArray() 抛 TypeError 500
            $knowledge = Knowledge::find($request->input('id'));
            if (!$knowledge) {
                return $this->fail([400202, '知识不存在']);
            }
            return $this->success($knowledge->toArray());
        }
        $data = Knowledge::select(['title', 'id', 'updated_at', 'category', 'show', 'language', 'sort',
            'visibility', 'slug', 'summary', 'published_at'])
            ->orderBy('sort', 'ASC')
            ->get();
        return $this->success($data);
    }

    public function getCategory(Request $request)
    {
        return $this->success(array_keys(Knowledge::get()->groupBy('category')->toArray()));
    }

    public function save(KnowledgeSave $request)
    {
        $params = $request->validated();
        $id = $params['id'] ?? null;
        $reviewed = $request->boolean('public_reviewed');
        unset($params['id'], $params['public_reviewed']);
        if (array_key_exists('show', $params) && $params['show'] === null) {
            unset($params['show']);
        }
        DB::transaction(function () use ($id, $params, $reviewed) {
            $knowledge = $id ? Knowledge::lockForUpdate()->findOrFail($id) : new Knowledge();
            // Missing fields from old admin clients keep their database values.
            $knowledge->fill($params);
            $this->publication->validateForSave($knowledge, $reviewed);
            $knowledge->save();
        });
        return $this->success(true);
    }

    public function show(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
            'show' => 'sometimes|required|boolean',
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        DB::transaction(function () use ($request) {
            $knowledge = Knowledge::lockForUpdate()->findOrFail($request->input('id'));
            $desired = $request->has('show') ? $request->boolean('show') : !$knowledge->show;
            if ($desired === $knowledge->show) {
                return;
            }
            if ($desired && $knowledge->visibility === 'public') {
                throw ValidationException::withMessages([
                    'public_reviewed' => ['公开文章请进入编辑页，审核内容后保存发布。'],
                ]);
            }
            $knowledge->show = $desired;
            if ($knowledge->show) {
                $this->publication->validateForSave($knowledge, false);
            }
            $knowledge->save();
        });

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误'
        ]);
        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                $knowledge = Knowledge::find($v);
                if (!$knowledge) {
                    continue;
                }
                $knowledge->timestamps = false;
                $knowledge->update(['sort' => $k + 1]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiException('保存失败');
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            return $this->fail([400202, '知识不存在']);
        }
        if (!$knowledge->delete()) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }
}
