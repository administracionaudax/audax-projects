@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.billing.summary') }}</h2>
<p class="lead">{{ __('report_pdf.billing.summary_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $summary])

<h2>{{ __('report_pdf.billing.entries_title') }}</h2>
@include('reports.pdf.partials.table', ['table' => $entries])
@if ($entries_more > 0)
<p class="alert">{{ __('report_pdf.billing.entries_more', ['count' => $entries_more]) }}</p>
@endif
@endsection
