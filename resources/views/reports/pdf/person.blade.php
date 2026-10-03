@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.person.by_client') }}</h2>
@include('reports.pdf.partials.table', ['table' => $clients])

<h2>{{ __('report_pdf.person.by_project') }}</h2>
@include('reports.pdf.partials.table', ['table' => $projects])

<h2>{{ __('report_pdf.person.by_type') }}</h2>
@include('reports.pdf.partials.table', ['table' => $types])

<h2>{{ __('report_pdf.person.unlogged') }}</h2>
<p class="lead">{{ __('report_pdf.person.unlogged_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $unlogged])

@if ($days !== null)
<h2>{{ __('report_pdf.person.days') }}</h2>
@include('reports.pdf.partials.table', ['table' => $days])
@endif
@endsection
