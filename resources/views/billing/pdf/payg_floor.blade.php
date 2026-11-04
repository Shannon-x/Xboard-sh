{{-- 按量月度保底凭证的明细（由 document.blade.php 引入，沿用它的样式）：用量 → 折合金额 → 月最低消费 → 补扣 → 扣后剩余。
     行和文案都是开具时写进快照的（BillingDocumentService::paygFloor），这里只排版；加粗的那一行是本次补扣。 --}}
<table class="items" width="100%" style="margin-top:18pt">
<tr>
<th width="40%">{{ $item_name }}</th>
<th style="text-align:right">{{ $month }}</th>
</tr>
@foreach($rows as $row)
@if(!empty($row['strong']))
<tr class="grand">
<td style="border-top:1.2pt solid #2a2520"><span class="item-name">{{ $row[0] }}</span></td>
<td class="right" style="border-top:1.2pt solid #2a2520"><span class="item-name">{{ $row[1] }}</span></td>
</tr>
@else
<tr>
<td class="muted">{{ $row[0] }}</td>
<td class="right">{{ $row[1] }}</td>
</tr>
@endif
@endforeach
</table>
