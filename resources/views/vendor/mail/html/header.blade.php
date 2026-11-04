@props(['url'])
{{-- LFS header: black band with the logo, then the Zambian flag stripe. --}}
@php
    // Email clients need an absolute, publicly reachable image URL, and most
    // do not render SVG, so a PNG copy of the logo lives in public/images/email.
    $logo = rtrim((string) config('app.url'), '/').'/images/email/lfs-logo.png';
@endphp
<tr>
<td class="header" align="center" bgcolor="#0f0f0f" style="background-color:#0f0f0f;">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ $logo }}" class="logo" width="210" alt="LFS — Lusaka Fitness Squad" style="width:210px;max-width:100%;height:auto;border:0;color:#ffffff;font-size:22px;">
</a>
</td>
</tr>
<tr>
<td style="font-size:0;line-height:0;">
<table class="stripe" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="stripe-green" width="34%" height="4" bgcolor="#198a4e" style="background-color:#198a4e;font-size:1px;line-height:4px;">&nbsp;</td>
<td class="stripe-red" width="33%" height="4" bgcolor="#c0392b" style="background-color:#c0392b;font-size:1px;line-height:4px;">&nbsp;</td>
<td class="stripe-orange" width="33%" height="4" bgcolor="#e07b39" style="background-color:#e07b39;font-size:1px;line-height:4px;">&nbsp;</td>
</tr>
</table>
</td>
</tr>
