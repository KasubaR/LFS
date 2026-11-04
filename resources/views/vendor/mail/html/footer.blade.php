{{-- LFS footer. The slot (copyright line) is passed in by message.blade.php. --}}
@php
    $whatsapp = (string) config('mail.contact_whatsapp');
    $contact = (string) config('mail.contact_address');
    // 260767023948 -> +260 767 023 948
    $whatsappLabel = preg_match('/^(\d{3})(\d{3})(\d{3})(\d{3})$/', $whatsapp, $m) ? "+{$m[1]} {$m[2]} {$m[3]} {$m[4]}" : '+'.$whatsapp;
@endphp
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p class="footer-brand" style="margin:0 0 4px;">Lusaka Fitness Squad</p>
<p style="margin:0 0 14px;">We're In This Together.</p>
<p class="footer-links" style="margin:0 0 14px;">
<a href="https://facebook.com/lfszambia">Facebook</a>
&nbsp;·&nbsp;
<a href="https://instagram.com/lfszambia">Instagram</a>
&nbsp;·&nbsp;
<a href="https://wa.me/{{ $whatsapp }}">WhatsApp</a>
&nbsp;·&nbsp;
<a href="{{ config('app.url') }}">Website</a>
</p>
<p style="margin:0 0 14px;">
Questions? WhatsApp <a href="https://wa.me/{{ $whatsapp }}">{{ $whatsappLabel }}</a>
or email <a href="mailto:{{ $contact }}">{{ $contact }}</a>
</p>
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
