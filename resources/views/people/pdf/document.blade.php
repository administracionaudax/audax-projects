{{-- Ficheros del registro de jornada (Fase 11, R2; D-351): portada, cifras clave y secciones con
     sus tablas, sobre la hoja de documentos de Audax (reports.pdf.layout). Al pie de cada página
     va el principio de la huella del contenido y, al final, la huella completa. --}}
@extends('reports.pdf.layout')

@section('content')
<style>@page{@bottom-left{content:"{{ __('people.reports.footer.short_hash', ['hash' => substr($content_hash, 0, 16)]) }}";font-size:6.5pt;color:#6b6b6b;}}</style>
@if ($kpis !== [])
@include('reports.pdf.partials.kpis')
@endif

@foreach ($sections as $section)
<h2>{{ $section['title'] }}</h2>
@if ($section['lead'] !== null)
<p class="lead">{{ $section['lead'] }}</p>
@endif
@include('reports.pdf.partials.table', ['table' => $section['table']])
@endforeach

@foreach ($notes as $note)
<p class="note">{{ $note }}</p>
@endforeach

<section class="register-hash">
  <h2>{{ __('people.reports.footer.title') }}</h2>
  <dl>
    <dt>{{ __('people.reports.footer.content_hash') }}</dt><dd class="hash">{{ $content_hash }}</dd>
    <dt>{{ __('people.reports.footer.generated') }}</dt><dd>{{ $generated_at }}@if ($generated_by) · {{ $generated_by }}@endif</dd>
  </dl>
  <p>{{ __('people.reports.footer.explanation') }}</p>
</section>
@endsection
