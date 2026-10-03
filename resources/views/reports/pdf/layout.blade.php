{{-- PDF e impresión de un informe (Fase 9, D-140): hoja de documentos A4 de Audax con DM Sans
     incrustada (ReportHtml). Cada informe extiende este layout con su sección «content». --}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title }}</title>
<style>{!! $theme !!}</style>
@if ($landscape)
<style>@page{size:A4 landscape;}</style>
@endif
</head>
<body class="report{{ $landscape ? ' report--landscape' : '' }}{{ $print ? ' report--print' : '' }}">
@include('reports.pdf.partials.cover')
@yield('content')
@if (! empty($definitions))
@include('reports.pdf.partials.definitions')
@endif
@if ($print)
<script nonce="{{ $nonce }}">window.addEventListener('load', function () { window.print(); });</script>
@endif
</body>
</html>
