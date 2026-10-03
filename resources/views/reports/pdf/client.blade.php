@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.client.projects') }}</h2>
<p class="lead">{{ __('report_pdf.client.projects_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $projects])

<h2>{{ __('report_pdf.client.banks') }}</h2>
<p class="lead">{{ __('report_pdf.client.banks_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $banks])

<h2>{{ __($timeline_monthly ? 'report_pdf.client.timeline_month' : 'report_pdf.client.timeline_week') }}</h2>
@include('reports.pdf.partials.table', ['table' => $timeline])
@endsection
