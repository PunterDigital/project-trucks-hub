@props(['url'])
{{-- Hub mail theme (#4661): the app icon (a PNG: Gmail and Outlook drop SVG) and the site name as a text wordmark. --}}
<tr>
<td class="header">
<a href="{{ $url }}" class="brand">
<img src="{{ rtrim((string) config('app.url'), '/') }}/apple-touch-icon.png" class="logo" width="36" height="36" alt="">
<span class="brand-name">{!! $slot !!}</span>
</a>
</td>
</tr>
