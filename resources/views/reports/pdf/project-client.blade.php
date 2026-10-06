@extends('reports.pdf.layout')

{{-- Informe de un proyecto para el cliente (D-241): solo las horas que vería en el portal, las
     personas como las ve él y las bolsas con las cifras del portal. Nunca importes. --}}
@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
<p class="lead">{{ $visibility }}</p>
@include('reports.pdf.partials.kpis')

@if ($banks !== null)
<h2>{{ __('report_pdf.project_client.banks') }}</h2>
<p class="lead">{{ __('report_pdf.project_client.banks_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $banks])
@endif

<h2>{{ __('report_pdf.project_client.tasks') }}</h2>
<p class="lead">{{ __('report_pdf.project_client.tasks_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $tasks])

@if ($people !== null)
<h2>{{ __('report_pdf.project.by_person') }}</h2>
@include('reports.pdf.partials.table', ['table' => $people])
@endif

<h2>{{ __('report_pdf.project.by_type') }}</h2>
@include('reports.pdf.partials.table', ['table' => $types])

<h2>{{ __('report_pdf.project.monthly') }}</h2>
@include('reports.pdf.partials.table', ['table' => $months])

@if ($weeks !== null)
<h2>{{ __('report_pdf.project.weekly') }}</h2>
@include('reports.pdf.partials.table', ['table' => $weeks])
@endif

<h2>{{ __('report_pdf.project_client.entries') }}</h2>
@include('reports.pdf.partials.table', ['table' => $entries])
@if ($entries_more > 0)
<p class="lead">{{ __('report_pdf.project_client.entries_more', ['count' => $entries_more]) }}</p>
@endif
@endsection
