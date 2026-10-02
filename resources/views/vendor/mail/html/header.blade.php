@props(['url'])
{{-- Cabecera de los emails (Fase 5, D-067): el logo de /admin/identidad si lo hay; si no, el texto. --}}
@php($brand = app(\App\Domain\Identity\CompanyIdentity::class)->forEmail())
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($brand !== null)
<img src="{{ $brand['url'] }}" width="{{ $brand['width'] }}" height="{{ $brand['height'] }}" alt="{{ $brand['name'] }}" style="border: 0; display: block; height: {{ $brand['height'] }}px; max-width: 100%; width: {{ $brand['width'] }}px;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
