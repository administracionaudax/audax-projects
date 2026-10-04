@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.direction.by_department') }}</h2>
@include('reports.pdf.partials.table', ['table' => $departments])

<h2>{{ __('report_pdf.direction.top_clients') }}</h2>
@include('reports.pdf.partials.table', ['table' => $clients])

<h2>{{ __('report_pdf.direction.top_projects') }}</h2>
@include('reports.pdf.partials.table', ['table' => $projects])

<h2>{{ __('report_pdf.direction.at_risk') }}</h2>
<p class="lead">{{ __('report_pdf.direction.at_risk_lead', ['threshold' => $at_risk_threshold]) }}</p>
@include('reports.pdf.partials.table', ['table' => $at_risk])

<h2>{{ __('report_pdf.direction.overdue') }}</h2>
@include('reports.pdf.partials.table', ['table' => $overdue])
@if ($overdue_more > 0)
<p class="lead">{{ __('report_pdf.direction.overdue_more', ['count' => $overdue_more]) }}</p>
@endif
@endsection
