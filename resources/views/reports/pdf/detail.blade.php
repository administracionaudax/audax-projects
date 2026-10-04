@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('report_pdf.detail.table') }}</h2>
@if ($truncated !== null)
<p class="alert">{{ $truncated }}</p>
@endif
@foreach ($tables as $table)
@include('reports.pdf.partials.table', ['table' => $table])
@endforeach
@endsection
