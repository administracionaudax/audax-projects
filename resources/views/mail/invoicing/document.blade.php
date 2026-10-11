{{-- Email de una factura propia (E2, App\Mail\SalesDocumentMail). Los textos llegan ya escapados.
     Cabecera y pie con el nombre del remitente (el emisor), no el de la app: lo recibe el cliente. --}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$buttonUrl">
{{ $company }}
</x-mail::header>
</x-slot:header>

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

<x-slot:subcopy>
<x-mail::subcopy>
{!! $linkNote !!}
</x-mail::subcopy>
</x-slot:subcopy>

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $company }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
