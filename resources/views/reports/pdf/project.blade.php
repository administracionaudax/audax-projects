@extends('reports.pdf.layout')

{{-- Informe interno y completo de un proyecto (D-240): todo lo de la página y, además, el resumen
     del proyecto, la matriz tarea × persona, los meses, las bolsas, los costes y el margen (solo con
     view-financials) y el listado de entradas. La versión para el cliente es project-client. --}}
@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.project.overview.title') }}</h2>
@include('reports.pdf.partials.table', ['table' => $overview])

<h2>{{ __('report_pdf.project.by_person') }}</h2>
@include('reports.pdf.partials.table', ['table' => $people])

<h2>{{ __('report_pdf.project.estimates') }}</h2>
<p class="lead">{{ __('report_pdf.project.estimates_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $estimates])
@if ($estimates_more > 0)
<p class="lead">{{ __('report_pdf.project.estimates_more', ['count' => $estimates_more]) }}</p>
@endif

<h2>{{ __('report_pdf.project.estimates_by_type') }}</h2>
@include('reports.pdf.partials.table', ['table' => $estimates_by_type])

<h2>{{ __('report_pdf.project.matrix') }}</h2>
<p class="lead">{{ __('report_pdf.project.matrix_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $matrix])
@if ($matrix_more > 0)
<p class="lead">{{ __('report_pdf.project.matrix_more', ['count' => $matrix_more]) }}</p>
@endif

<h2>{{ __('report_pdf.project.weekly') }}</h2>
@include('reports.pdf.partials.table', ['table' => $weeks])

<h2>{{ __('report_pdf.project.monthly') }}</h2>
@include('reports.pdf.partials.table', ['table' => $months])

<h2>{{ __('report_pdf.project.by_type') }}</h2>
@include('reports.pdf.partials.table', ['table' => $types])

@if ($banks !== null)
<h2>{{ __('report_pdf.project.banks') }}</h2>
<p class="lead">{{ __('report_pdf.project.banks_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $banks])
@endif

@if ($costs !== null)
<h2>{{ __('report_pdf.project.costs') }}</h2>
<p class="lead">{{ $income_basis }} {{ __('report_pdf.project.costs_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $costs])
@endif

<h2>{{ __('report_pdf.project.status') }}</h2>
@if ($overdue_tasks > 0)
<p class="alert">{{ trans_choice('report_pdf.project.overdue_tasks', $overdue_tasks, ['count' => $overdue_tasks]) }}</p>
@endif
@include('reports.pdf.partials.table', ['table' => $status])

<h2>{{ __('report_pdf.project.milestones') }}</h2>
@include('reports.pdf.partials.table', ['table' => $milestones])

<h2>{{ __('report_pdf.project.entries') }}</h2>
@include('reports.pdf.partials.table', ['table' => $entries])
@if ($entries_more > 0)
<p class="lead">{{ __('report_pdf.project.entries_more', ['count' => $entries_more]) }}</p>
@endif
@endsection
