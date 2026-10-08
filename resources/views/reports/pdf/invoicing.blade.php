@extends('reports.pdf.layout')

{{-- Informe de facturación (D-400): cifras, meses, servicios, clientes, antigüedad y vencidas. --}}
@section('content')
<h2>{{ __('report_pdf.sections.kpis') }}</h2>
@include('reports.pdf.partials.kpis')

<h2>{{ __('billing.invoicing.charts.months') }}</h2>
@include('reports.pdf.partials.table', ['table' => $months])

<h2>{{ __('billing.invoicing.charts.services') }}</h2>
@if ($services_filtered)
<p class="lead">{{ __('billing.invoicing.services_filtered') }}</p>
@endif
@include('reports.pdf.partials.table', ['table' => $services])

<h2>{{ __('billing.invoicing.charts.clients') }}</h2>
@include('reports.pdf.partials.table', ['table' => $clients])

<h2>{{ __('billing.invoicing.charts.aging') }}</h2>
<p class="lead">{{ __('billing.invoicing.aging_lead') }}</p>
@include('reports.pdf.partials.table', ['table' => $aging])

<h2>{{ __('billing.invoicing.charts.overdue') }}</h2>
@include('reports.pdf.partials.table', ['table' => $overdue])
@if ($overdue_more > 0)
<p class="lead">{{ trans_choice('billing.invoicing.overdue_more', $overdue_more, ['count' => $overdue_more]) }}</p>
@endif
@endsection
