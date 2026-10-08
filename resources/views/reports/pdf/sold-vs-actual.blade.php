@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('billing.report.pdf.units') }}</h2>
<p class="lead">{{ __('billing.report.pdf.units_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $summary])

@endsection
