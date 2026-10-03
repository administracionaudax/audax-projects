@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.department.members') }}</h2>
<p class="lead">{{ __('report_pdf.department.members_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $members])

<h2>{{ __('report_pdf.department.by_client') }}</h2>
@include('reports.pdf.partials.table', ['table' => $clients])
@endsection
