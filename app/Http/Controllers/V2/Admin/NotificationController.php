<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Services\Notification\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 后台「通知偏好」：各类别的退订人数与来源、单个用户的偏好、代用户改。
 */
class NotificationController extends Controller
{
    /** 每个类别关掉的人数、按来源拆分，以及最近 30 天的退订数 */
    public function stats()
    {
        $rows = UserNotificationPref::where('enabled', false)
            ->select('category', 'source', DB::raw('count(*) as total'))
            ->groupBy('category', 'source')
            ->get();
        $categories = [];
        foreach (NotificationPreference::CATEGORIES as $category) {
            $categories[$category] = ['category' => $category, 'optional' => NotificationPreference::isOptional($category), 'disabled' => 0, 'by_source' => []];
        }
        foreach ($rows as $row) {
            if (!isset($categories[$row->category])) {
                continue;
            }
            $categories[$row->category]['disabled'] += (int) $row->total;
            $categories[$row->category]['by_source'][$row->source] = (int) $row->total;
        }
        $since = time() - 30 * 86400;
        $recent = UserNotificationPref::where('enabled', false)->where('updated_at', '>=', $since)->count();
        return $this->success([
            'categories' => array_values($categories),
            'bulk' => NotificationPreference::BULK,
            'optional' => NotificationPreference::optionalCategories(),
            'recent_30d' => $recent,
            'users_with_optout' => UserNotificationPref::where('enabled', false)->distinct('user_id')->count('user_id'),
        ]);
    }

    /** 某位用户（user_id 或 email）的偏好与来源 */
    public function fetch(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer',
            'email' => 'nullable|string|max:191',
        ]);
        $user = $this->findUser($request);
        if (!$user) {
            return $this->fail([404, __('The user does not exist')]);
        }
        return $this->success($this->payload($user));
    }

    /** 最近的退订记录（分页，给后台列表看谁从哪里退订了什么） */
    public function log(Request $request)
    {
        $request->validate([
            'category' => 'nullable|string|max:32',
            'source' => 'nullable|string|max:24',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $query = UserNotificationPref::where('enabled', false)->orderBy('updated_at', 'desc');
        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }
        if ($request->filled('source')) {
            $query->where('source', (string) $request->input('source'));
        }
        $page = $query->paginate(
            perPage: max(1, min(100, $request->integer('pageSize', 20))),
            page: max(1, $request->integer('current', 1)),
        );
        $emails = User::whereIn('id', $page->getCollection()->pluck('user_id')->unique()->all())->pluck('email', 'id');
        return response([
            'data' => $page->getCollection()->map(fn (UserNotificationPref $row) => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'email' => $emails[$row->user_id] ?? null,
                'category' => $row->category,
                'source' => $row->source,
                'ip' => $row->ip,
                'updated_at' => (int) $row->updated_at,
            ])->values(),
            'total' => $page->total(),
        ]);
    }

    /** 代用户改：body { user_id, prefs: { category: bool } } */
    public function save(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'prefs' => 'required|array|min:1',
            'prefs.*' => 'boolean',
        ]);
        $user = User::find((int) $request->input('user_id'));
        if (!$user) {
            return $this->fail([404, __('The user does not exist')]);
        }
        $prefs = [];
        foreach ((array) $request->input('prefs') as $category => $enabled) {
            if (!NotificationPreference::isCategory((string) $category)) {
                return $this->fail([422, 'category 不合法']);
            }
            $prefs[(string) $category] = (bool) $enabled;
        }
        NotificationPreference::setMany($user, $prefs, UserNotificationPref::SOURCE_ADMIN, $request->ip());
        return $this->success($this->payload($user));
    }

    /** 换掉用户的免登录凭据：此前所有邮件里的偏好链接与一键退订地址作废 */
    public function rotate(Request $request)
    {
        $request->validate(['user_id' => 'required|integer']);
        $user = User::find((int) $request->input('user_id'));
        if (!$user) {
            return $this->fail([404, __('The user does not exist')]);
        }
        NotificationPreference::rotate($user);
        return $this->success(true);
    }

    private function findUser(Request $request): ?User
    {
        if ($request->filled('user_id')) {
            return User::find((int) $request->input('user_id'));
        }
        if ($request->filled('email')) {
            return User::byEmail((string) $request->input('email'))->first();
        }
        return null;
    }

    private function payload(User $user): array
    {
        return ['user_id' => (int) $user->id, 'email' => $user->email, 'has_key' => (bool) $user->notify_key] + NotificationPreference::view($user);
    }
}
