{{-- 收据 / 续费账单 PDF（mPDF 渲染：只用表格排版，不用 flex/grid；字体名对应 BillingDocumentService 注册的 fontdata） --}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $doc_title }} {{ $doc_no }}</title>
<style>
@page { background-color: #f8f7f3; }
body { font-family: notosans; font-size: 9.5pt; color: #2a2520; line-height: 1.5; }
table { border-collapse: collapse; }
td, th { vertical-align: top; }
.serif { font-family: notoserif; }
.muted { color: #69635e; }
.faint { color: #a09890; }
.right { text-align: right; }
.brand { font-family: notoserif; font-size: 17pt; color: #716a65; letter-spacing: 1.5pt; }
.eyebrow { font-size: 8pt; letter-spacing: 3pt; color: #a09890; }
.doc-title { font-family: notoserif; font-size: 24pt; color: #2a2520; letter-spacing: 1pt; line-height: 1.2; }
.stamp td { font-family: notoserif; font-size: 10pt; letter-spacing: 3pt; color: #c94f2e; border: 1.5pt solid #c94f2e; padding: 3pt 9pt 3pt 12pt; }
.stamp-soft td { color: #716a65; border-color: #c5beb5; }
.rule { border-top: 0.8pt solid #e5e0d8; }
.label { font-size: 7.5pt; letter-spacing: 1.5pt; color: #a09890; }
.meta td { padding: 1.5pt 0; font-size: 9pt; }
.meta .k { color: #a09890; width: 68pt; }
.items th { font-size: 7.5pt; letter-spacing: 1.5pt; color: #a09890; text-align: left; border-bottom: 1.2pt solid #2a2520; padding: 0 4pt 5pt 4pt; font-weight: normal; }
.items td { padding: 9pt 4pt; border-bottom: 0.6pt solid #e5e0d8; }
.item-name { font-size: 11pt; font-weight: bold; }
.item-details { font-size: 8pt; color: #69635e; margin-top: 2pt; }
.totals td { padding: 2.5pt 4pt; font-size: 9.5pt; }
.totals .grand td { border-top: 1.2pt solid #2a2520; padding-top: 6pt; font-family: notoserif; font-size: 12.5pt; }
.totals .due td { font-family: notoserif; font-size: 12.5pt; color: #c94f2e; }
.callout { border-left: 2.5pt solid #c94f2e; background-color: #f0d9d1; padding: 7pt 11pt; font-size: 9pt; }
.note { border-left: 2.5pt solid #c5beb5; background-color: #f1efe9; padding: 7pt 11pt; font-size: 9pt; color: #69635e; }
.card { border: 0.6pt solid #e5e0d8; background-color: #fbfaf7; }
.card td { padding: 1.5pt 10pt; line-height: 1.4; }
.card .card-name { font-family: notoserif; font-size: 10.5pt; padding-top: 8pt; }
.card .card-price { color: #c94f2e; font-weight: bold; font-size: 9.5pt; }
.card .card-details { font-size: 7.5pt; color: #69635e; }
.card .card-link { font-size: 7.5pt; padding-bottom: 8pt; }
.foot { font-size: 7.5pt; color: #a09890; }
a { color: #c94f2e; text-decoration: none; }
</style>
</head>
<body>
<htmlpagefooter name="billing">
<table width="100%" class="foot"><tr>
<td>{{ $app_name }} · {{ $doc_title }} {{ $doc_no }}</td>
<td class="right">{{ __('billing.footer.page') }}</td>
</tr></table>
</htmlpagefooter>
<sethtmlpagefooter name="billing" value="on" />

{{-- 抬头：左品牌，右文件名 + 状态章 --}}
<table width="100%"><tr>
<td width="55%">
<table><tr>
@if($logo_data)<td style="vertical-align:middle;padding-right:9pt"><img src="{{ $logo_data }}" style="height:14mm"></td>@endif
<td style="vertical-align:middle">
<div class="brand">{{ $app_name }}</div>
@if($issuer)<div class="faint" style="font-size:8pt;margin-top:4pt">{{ $issuer }}</div>@endif
<div class="faint" style="font-size:8pt;{{ $issuer ? '' : 'margin-top:4pt' }}">{{ $app_url }}</div>
</td>
</tr></table>
</td>
<td width="45%" class="right">
<div class="eyebrow">{{ $doc_title_en }}</div>
<div class="doc-title">{{ $doc_title }}</div>
<table align="right" class="stamp {{ $stamp_soft ? 'stamp-soft' : '' }}" style="margin-top:8pt"><tr><td>{{ $stamp }}</td></tr></table>
</td>
</tr></table>
<div class="rule" style="margin-top:14pt"></div>

{{-- 寄往 + 编号信息 --}}
<table width="100%" style="margin-top:12pt"><tr>
<td width="50%">
<div class="label">{{ __('billing.field.bill_to') }}</div>
<div style="font-size:10.5pt;margin-top:3pt">{{ $bill_to['email'] }}</div>
<div class="muted" style="font-size:8pt">{{ __('billing.field.account_id') }} {{ $bill_to['id'] }}</div>
</td>
<td width="50%">
<table class="meta" width="100%">
@foreach($meta as $row)
<tr><td class="k">{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
@endforeach
</table>
</td>
</tr></table>

@if($items)
{{-- 明细 --}}
<table class="items" width="100%" style="margin-top:18pt">
<tr>
<th width="{{ $kind === 'receipt' ? 50 : 66 }}%">{{ __('billing.field.item') }}</th>
<th width="16%">{{ __('billing.field.period') }}</th>
@if($kind === 'receipt')<th width="18%">{{ __('billing.field.service_until') }}</th>@endif
<th class="right">{{ __('billing.field.amount') }}</th>
</tr>
@foreach($items as $item)
<tr>
<td><div class="item-name">{{ $item['name'] }}</div>@if($item['details'])<div class="item-details">{{ implode(' · ', $item['details']) }}</div>@endif</td>
<td>{{ $item['period'] }}</td>
@if($kind === 'receipt')<td>{{ $item['until'] ?? '—' }}</td>@endif
<td class="right">{{ $item['amount_fmt'] }}</td>
</tr>
@endforeach
</table>

{{-- 合计 --}}
<table width="100%" style="margin-top:4pt"><tr><td width="52%"></td><td>
<table class="totals" width="100%">
@foreach($totals as $row)
<tr><td class="muted">{{ $row['label'] }}</td><td class="right">{{ $row['amount_fmt'] }}</td></tr>
@endforeach
@if($kind === 'receipt')
<tr class="grand"><td>{{ __('billing.field.total') }}</td><td class="right">{{ $total_fmt }}</td></tr>
@foreach($payments as $row)
<tr><td class="muted">{{ $row['label'] }} <span class="faint">· {{ $row['method'] }}</span></td><td class="right">-{{ $row['amount_fmt'] }}</td></tr>
@endforeach
<tr class="due"><td>{{ __('billing.field.balance_due') }}</td><td class="right">{{ $balance_due_fmt }}</td></tr>
@else
<tr class="grand due"><td>{{ __('billing.field.amount_due') }}</td><td class="right">{{ $balance_due_fmt }}</td></tr>
@if($auto_covered)
<tr><td class="muted">{{ __('billing.field.account_balance') }}</td><td class="right">{{ $account_balance_fmt }}</td></tr>
@endif
@endif
</table>
</td></tr></table>
@endif

{{-- 说明 / 续费入口 --}}
@if($kind === 'invoice')
<div class="{{ $auto_covered ? 'note' : 'callout' }}" style="margin-top:16pt">
@if($items){{ __('billing.invoice.due_note', ['date' => $due_at]) }} · @endif<a href="{{ $cta_url }}">{{ $cta_label }}</a> <span class="faint">{{ $cta_url }}</span>
<div style="margin-top:3pt">{{ $intro }}</div>
</div>
@endif
@foreach($notes as $note)
<div class="note" style="margin-top:8pt">{{ $note }}</div>
@endforeach

{{-- 其他可购套餐 --}}
@if($kind === 'invoice' && $alternatives)
<div class="label" style="margin-top:22pt">{{ __('billing.invoice.alternatives') }}</div>
<div class="muted" style="font-size:8.5pt;margin-top:2pt">{{ __('billing.invoice.alternatives_hint') }}</div>
<table width="100%" style="margin-top:8pt"><tr>
@foreach($alternatives as $alt)
<td width="{{ intdiv(100, count($alternatives)) }}%" style="padding-right:{{ $loop->last ? 0 : 6 }}pt">
<table width="100%" class="card">
<tr><td class="card-name">{{ $alt['name'] }}</td></tr>
<tr><td class="card-price">{{ $alt['price'] }}</td></tr>
<tr><td class="card-details">{{ implode(' · ', $alt['details']) }}</td></tr>
<tr><td class="card-link"><a href="{{ $alt['url'] }}">{{ __('billing.invoice.view_plan') }}</a></td></tr>
</table>
</td>
@endforeach
</tr></table>
@endif

<div class="rule" style="margin-top:24pt"></div>
<div class="foot" style="margin-top:6pt">{{ __('billing.footer.generated') }} {{ __('billing.footer.questions') }}</div>
</body>
</html>
