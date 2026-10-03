@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.project.estimates_by_type') }}</h2>
@include('reports.pdf.partials.table', ['table' => $estimates_by_type])

<h2>{{ __('report_pdf.project.estimates') }}</h2>
<p class="lead">{{ __('report_pdf.project.estimates_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $estimates])
@if ($estimates_more > 0)
<p class="lead">{{ __('report_pdf.project.estimates_more', ['count' => $estimates_more]) }}</p>
@endif

<h2>{{ __('report_pdf.project.by_person') }}</h2>
@include('reports.pdf.partials.table', ['table' => $people])

<h2>{{ __('report_pdf.project.by_type') }}</h2>
@include('reports.pdf.partials.table', ['table' => $types])

<h2>{{ __('report_pdf.project.weekly') }}</h2>
@include('reports.pdf.partials.table', ['table' => $weeks])

<h2>{{ __('report_pdf.project.status') }}</h2>
@if ($overdue_tasks > 0)
<p class="alert">{{ trans_choice('report_pdf.project.overdue_tasks', $overdue_tasks, ['count' => $overdue_tasks]) }}</p>
@endif
@include('reports.pdf.partials.table', ['table' => $status])

<h2>{{ __('report_pdf.project.milestones') }}</h2>
@include('reports.pdf.partials.table', ['table' => $milestones])
@endsection
