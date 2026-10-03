@extends('reports.pdf.layout')

@section('content')
<h2>{{ __('reports.r2.pdf.bar_label') }}</h2>
<dl class="kpis">
  @foreach ($kpis as $kpi)
  <div class="kpi">
    <dt>{{ $kpi['label'] }}</dt>
    <dd @class([$kpi['class'] ?? null])>{{ $kpi['value'] }}</dd>
    @if ($kpi['detail'] !== null)<p>{{ $kpi['detail'] }}</p>@endif
  </div>
  @endforeach
</dl>

<div class="meter" role="img" aria-label="{{ __('reports.r2.pdf.bar_label') }}">
  @foreach ($meter['segments'] as $segment)<span class="{{ $segment['class'] }}" style="width:{{ $segment['width'] }}%"></span>@endforeach
  @if ($meter['mark'] !== null)<i class="mark" style="left:calc({{ $meter['mark'] }}% - .75pt)"></i>@endif
</div>
<p class="legend">
  @foreach ($legend as $item)<span><i class="{{ $item['class'] }}"></i>{{ $item['label'] }}</span>@endforeach
</p>

@foreach ($notes as $note)
<p class="note">{{ $note }}</p>
@endforeach
@if ($partial !== null)
<p class="alert">{{ $partial }}</p>
@endif

@if ($financials !== null)
<h2>{{ __('reports.r2.pdf.financials') }}</h2>
<table>
  <tbody>
    @foreach ($financials as [$label, $value])
    <tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>
    @endforeach
  </tbody>
</table>
@endif

@if ($months !== null)
<h2>{{ __('reports.r2.pdf.monthly') }}</h2>
@include('reports.pdf.partials.table', ['table' => $months])
@endif

<h2>{{ __('reports.r2.pdf.entries') }}</h2>
@include('reports.pdf.partials.table', ['table' => $entries])
@endsection
