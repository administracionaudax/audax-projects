@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.hours.entries') }}</h2>
@include('reports.pdf.partials.table', ['table' => $entries])
@if ($more > 0)
<p class="alert">{{ __('report_pdf.hours.more', ['count' => $more]) }}</p>
@endif
@endsection
