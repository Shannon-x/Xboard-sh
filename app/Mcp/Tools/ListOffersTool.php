<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\McpOffers;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_offers')]
#[Title('优惠与公告')]
#[Description('现在的活动：站点公告，以及公告里给出的优惠码（折扣、适用套餐和周期、截止时间、这位客户还能不能用）。客户要买、续费、加购，或问「有没有优惠」时先调它；有适用的优惠码就主动告诉客户，并在 quote_plan / create_order 里带上 coupon_code。')]
#[IsReadOnly]
#[IsIdempotent]
class ListOffersTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return self::result(McpOffers::view($user) + [
            'note' => '只列公告里公开的优惠码。同一单只能用一个优惠码；被邀请人首单折扣与优惠码取较大者，以下单结果为准。',
        ]);
    }
}
