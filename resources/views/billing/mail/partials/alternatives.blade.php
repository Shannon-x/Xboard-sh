{{-- 「也可以看看」套餐列表：续费账单、到期通知、挽回邮件共用 --}}
                    @if($alternatives)
                    <!-- Other plans -->
                    <tr>
                        <td style="padding:36px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:11px;letter-spacing:2px;color:#a09890;">{{ __('billing.invoice.alternatives') }}</div>
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:13px;color:#69635e;line-height:1.7;margin-top:4px;">{{ __('billing.invoice.alternatives_hint') }}</div>
                        </td>
                    </tr>
                    @foreach($alternatives as $alt)
                    <tr>
                        <td style="padding:10px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#fbfaf7;border:1px solid #e5e0d8;border-radius:4px;">
                                <tr>
                                    <td style="padding:14px 18px;">
                                        <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:15px;font-weight:600;color:#2a2520;line-height:1.4;">{{ $alt['name'] }}</div>
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#69635e;line-height:1.7;margin-top:2px;">{{ implode(' · ', $alt['details']) }}</div>
                                    </td>
                                    <td align="right" valign="middle" style="padding:14px 18px;white-space:nowrap;">
                                        <div style="font-family:'Noto Sans SC',sans-serif;font-size:13px;font-weight:700;color:#c94f2e;">{{ $alt['price'] }}</div>
                                        <a href="{{ $alt['url'] }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#716a65;text-decoration:underline;">{{ __('billing.invoice.view_plan') }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endforeach
                    @endif
