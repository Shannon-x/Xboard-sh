<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketAttachmentService;
use App\Services\TicketCategory\TicketCategories;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    private const FILTERABLE_FIELDS = [
        'id',
        'user_id',
        'subject',
        'level',
        'category',
        'type',
        'feedback_state',
        'status',
        'reply_status',
        'last_reply_user_id',
        'created_at',
        'updated_at',
    ];

    private const SORTABLE_FIELDS = [
        'id',
        'user_id',
        'level',
        'category',
        'type',
        'status',
        'reply_status',
        'last_reply_user_id',
        'created_at',
        'updated_at',
    ];

    private function applyFiltersAndSorts(Request $request, $builder)
    {
        if ($request->has('filter')) {
            collect($request->input('filter'))->each(function ($filter) use ($builder) {
                if (!is_array($filter) || !isset($filter['id'])) {
                    return;
                }

                $key = (string) $filter['id'];
                if (!in_array($key, self::FILTERABLE_FIELDS, true)) {
                    return;
                }

                $value = $filter['value'] ?? '';
                $builder->where(function ($query) use ($key, $value) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, 'like', "%{$value}%");
                    }
                });
            });
        }

        if ($request->has('sort')) {
            collect($request->input('sort'))->each(function ($sort) use ($builder) {
                if (!is_array($sort) || !isset($sort['id'])) {
                    return;
                }

                $key = (string) $sort['id'];
                if (!in_array($key, self::SORTABLE_FIELDS, true)) {
                    return;
                }

                $value = !empty($sort['desc']) ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            return $this->fetchTicketById($request);
        } else {
            return $this->fetchTickets($request);
        }
    }

    /**
     * Summary of fetchTicketById
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function fetchTicketById(Request $request)
    {
        $ticket = Ticket::with('messages.attachments', 'user')->find($request->input('id'));

        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }
        $result = $ticket->toArray();
        $result['user'] = UserController::transformUserData($ticket->user);

        return $this->success($result);
    }

    /**
     * Summary of fetchTickets
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    private function fetchTickets(Request $request)
    {
        $ticketModel = Ticket::with('user')
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->has('reply_status'), function ($query) use ($request) {
                $query->whereIn('reply_status', $request->input('reply_status'));
            })
            // 分类 / 类型 / 跟进状态：单值或数组都行，精确匹配（filter[] 那套是 like，category 用 like 会把 other 和 others 混在一起）
            ->when($request->filled('category'), function ($query) use ($request) {
                $query->whereIn('category', (array) $request->input('category'));
            })
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->whereIn('type', array_map('intval', (array) $request->input('type')));
            })
            ->when($request->filled('feedback_state'), function ($query) use ($request) {
                $query->whereIn('feedback_state', (array) $request->input('feedback_state'));
            })
            ->when($request->has('email'), function ($query) use ($request) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('email', $request->input('email'));
                });
            });

        $this->applyFiltersAndSorts($request, $ticketModel);
        $tickets = $ticketModel
            ->latest('updated_at')
            ->paginate(
                perPage: $request->integer('pageSize', 10),
                page: $request->integer('current', 1)
            );

        // 获取items然后映射转换
        $items = collect($tickets->items())->map(function ($ticket) {
            $ticketData = $ticket->toArray();
            $ticketData['user'] = UserController::transformUserData($ticket->user);
            return $ticketData;
        })->all();

        return response([
            'data' => $items,
            'total' => $tickets->total()
        ]);
    }

    public function reply(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric',
            'message' => 'nullable|string|max:10000',
            'attachment_ids' => 'nullable|array|max:10',
            'attachment_ids.*' => 'integer|min:1',
        ], [
            'id.required' => '工单ID不能为空',
        ]);
        $attachmentIds = (new TicketAttachmentService())->validatePendingIds(
            $request->input('attachment_ids', []),
            $request->user()->id
        );
        $message = trim((string) $request->input('message', ''));
        if ($message === '' && empty($attachmentIds)) {
            return $this->fail([422, '消息不能为空']);
        }
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $message,
            $request->user()->id,
            $attachmentIds
        );
        return $this->success(true);
    }

    /**
     * 改分类 / 优先级 / 建议反馈的跟进状态。只改传了的字段。
     */
    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric',
            'category' => 'nullable|string|in:' . implode(',', array_keys(TicketCategories::CATEGORIES)),
            'level' => 'nullable|integer|in:0,1,2',
            'feedback_state' => 'nullable|string|in:' . implode(',', array_keys(TicketCategories::FEEDBACK_STATES)),
        ], [
            'id.required' => '工单ID不能为空',
            'category.in' => '分类不存在',
            'feedback_state.in' => '跟进状态不存在',
        ]);
        $ticket = Ticket::find($request->input('id'));
        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }
        $ticket = (new TicketService())->updateByAdmin($ticket, $request->only(['category', 'level', 'feedback_state']));
        return $this->success($ticket->toArray());
    }

    /**
     * 分类字典 + 工作台计数：后台侧边栏 / 筛选条用。
     *
     * counts.by_category 只数「开启中」的工单（待处理的工作量），
     * counts.feedback_by_state 数全部建议与反馈（含已关闭的），用来看采纳率。
     */
    public function stat()
    {
        $openByCategory = Ticket::where('status', Ticket::STATUS_OPENING)
            ->selectRaw('category, count(*) as total, sum(case when reply_status = ? then 1 else 0 end) as pending', [Ticket::REPLY_STATUS_PENDING])
            ->groupBy('category')
            ->get()
            ->mapWithKeys(fn($row) => [$row->category => ['open' => (int) $row->total, 'pending' => (int) $row->pending]]);

        $feedbackByState = Ticket::where('type', TicketCategories::TYPE_FEEDBACK)
            ->selectRaw('feedback_state, count(*) as total')
            ->groupBy('feedback_state')
            ->pluck('total', 'feedback_state')
            ->map(fn($v) => (int) $v);

        return $this->success([
            ...TicketCategories::toAdminArray(),
            'counts' => [
                'by_category' => $openByCategory,
                'feedback_by_state' => $feedbackByState,
                'pending_reply' => Ticket::where('status', Ticket::STATUS_OPENING)
                    ->where('reply_status', Ticket::REPLY_STATUS_PENDING)
                    ->count(),
            ],
        ]);
    }

    public function close(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '工单ID不能为空'
        ]);
        try {
            $ticket = Ticket::findOrFail($request->input('id'));
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->save();
            return $this->success(true);
        } catch (ModelNotFoundException $e) {
            return $this->fail([400202, '工单不存在']);
        } catch (\Exception $e) {
            return $this->fail([500101, '关闭失败']);
        }
    }

    public function show($ticketId)
    {
        $ticket = Ticket::with([
            'user',
            'messages' => function ($query) {
                $query->with(['user', 'attachments']); // 如果需要用户信息
            }
        ])->findOrFail($ticketId);

        // 自动包含 is_me 属性
        return response()->json([
            'data' => $ticket
        ]);
    }
}
