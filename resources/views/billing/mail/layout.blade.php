{{-- 收据 / 续费账单邮件外框：沿用 editorial 主题的米色纸感、衬线标题与 #c94f2e 主色；正文块由子视图的 summary 区块提供 --}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ $locale }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&family=Noto+Serif+SC:wght@400;600;700&display=swap" rel="stylesheet" />
    <!--<![endif]-->
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f3ef;font-family:'Noto Sans SC','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;-webkit-font-smoothing:antialiased;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color:#f5f3ef;">
        <tr>
            <td align="center" style="padding:56px 16px;">
                <table width="560" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;background-color:#f8f7f3;border:1px solid #e5e0d8;border-radius:6px;">
                    <!-- Brand -->
                    <tr>
                        <td style="padding:40px 44px 0 44px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <table border="0" cellspacing="0" cellpadding="0">
                                            <tr>
                                                @if($logo_url)
                                                {{-- 高固定 40px，宽按比例：Outlook 只认属性，不认 CSS 的 width:auto --}}
                                                <td style="padding-right:12px;vertical-align:middle;"><img src="{{ $logo_url }}" alt="{{ $app_name }}" height="{{ $logo_size['height'] ?? 40 }}"@if($logo_size) width="{{ $logo_size['width'] }}"@endif style="display:block;height:{{ $logo_size['height'] ?? 40 }}px;width:{{ $logo_size ? $logo_size['width'] . 'px' : 'auto' }};max-width:160px;border:0;outline:none;text-decoration:none;" /></td>
                                                @endif
                                                <td style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:18px;font-weight:600;color:#716a65;letter-spacing:1.5px;vertical-align:middle;">{{ $app_name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td align="right" style="font-family:'Noto Sans SC',sans-serif;font-size:11px;color:#a09890;letter-spacing:2px;text-transform:uppercase;vertical-align:middle;white-space:nowrap;">{{ $doc_title_en }}@if($doc_no !== '') · {{ $doc_no }}@endif</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Title -->
                    <tr>
                        <td style="padding:32px 44px 0 44px;">
                            <div style="font-family:'Noto Serif SC',Georgia,'Songti SC',serif;font-size:26px;font-weight:700;color:#2a2520;line-height:1.4;letter-spacing:0.5px;">{{ $headline }}</div>
                        </td>
                    </tr>
                    <!-- Intro -->
                    <tr>
                        <td style="padding:14px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;font-size:15px;color:#69635e;line-height:1.8;">{{ $intro }}</div>
                        </td>
                    </tr>
                    @yield('summary')
                    <!-- CTA -->
                    <tr>
                        <td style="padding:28px 44px 0 44px;">
                            <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-radius:4px;background-color:#c94f2e;">
                                        <a href="{{ $cta_url }}" target="_blank" style="font-family:'Noto Sans SC',sans-serif;display:inline-block;padding:11px 28px;font-size:14px;font-weight:500;color:#f8f7f3;text-decoration:none;letter-spacing:0.5px;">{{ $cta_label }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @if(!empty($pay_note))
                    <tr>
                        <td style="padding:12px 44px 0 44px;">
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;">{{ $pay_note }}</div>
                        </td>
                    </tr>
                    @endif
                    @yield('after_cta')
                    <!-- Attachment note -->
                    <tr>
                        <td style="padding:{{ $has_pdf ? '24px' : '12px' }} 44px 40px 44px;">
                            @if($has_pdf)
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;">{{ __('billing.footer.attachment', ['file' => $attachment_name]) }}</div>
                            @endif
                            @if(!empty($archive_note))
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#a09890;line-height:1.7;margin-top:4px;">{{ __('billing.archive.note') }} <a href="{{ $archive_url }}" target="_blank" style="color:#a09890;text-decoration:underline;">{{ __('billing.archive.link') }}</a></div>
                            @endif
                        </td>
                    </tr>
                </table>
                <!-- Footer -->
                <table width="560" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;">
                    <tr>
                        <td style="padding:28px 0;text-align:center;">
                            <div style="font-family:'Noto Serif SC',Georgia,serif;font-size:13px;color:#a09890;line-height:2.0;">
                                <a href="{{ $app_url }}" style="color:#716a65;text-decoration:none;letter-spacing:1px;">{{ $app_name }}</a>
                            </div>
                            <div style="font-family:'Noto Sans SC',sans-serif;font-size:12px;color:#c5beb5;margin-top:2px;">
                                {{ __('billing.footer.questions') }} <a href="{{ $settings_url }}" style="color:#a09890;text-decoration:underline;">{{ __('billing.footer.manage') }}</a>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
