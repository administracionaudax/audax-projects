{{-- Correo de un informe (D-141, App\Mail\ReportDeliveryMail). Los textos llegan ya escapados. --}}
<x-mail::message>
{!! $greeting !!}

{!! $intro !!}

@foreach ($noteLines as $line)
{!! $line !!}

@endforeach
**{!! $title !!}**
@if ($periodLine !== null)
<br>{!! $periodLine !!}
@endif

@if ($attachedNote !== null)
{!! $attachedNote !!}

@endif
@if ($downloadLinks !== [])
{!! $linksNote !!}

@foreach ($downloadLinks as $link)
<x-mail::button :url="$link['url']">
{{ $link['label'] }}
</x-mail::button>

{!! $link['expires'] !!}

@endforeach
@endif
{!! nl2br(e($salutation)) !!}
</x-mail::message>
