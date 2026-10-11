{{-- Email de una factura propia (E2, App\Mail\SalesDocumentMail). Los textos llegan ya escapados. --}}
<x-mail::message>
@if ($testNotice !== null)
<x-mail::panel>
{!! $testNotice !!}
</x-mail::panel>

@endif
@foreach ($paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
<x-mail::button :url="$buttonUrl">
{{ $buttonLabel }}
</x-mail::button>

<x-mail::subcopy>
{!! $linkNote !!}
</x-mail::subcopy>
</x-mail::message>
