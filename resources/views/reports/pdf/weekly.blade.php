{{-- El informe de la weekly (Fase 10, F-083, D-192): resumen global, riesgos y un bloque por cliente. --}}
@extends('reports.pdf.layout')

@section('content')
@include('reports.pdf.partials.kpis')

@if ($report === null)
<p class="empty">{{ __('weeklies.pdf.no_report') }}</p>
@else
<h2>{{ __('weeklies.pdf.global_summary') }}</h2>
<p class="weekly-text">{{ $report['global_summary'] }}</p>

@if ($report['team_risks'] !== [])
<div class="alert">
  <span class="weekly-alert-title">{{ __('weeklies.pdf.team_risks') }}</span>
  <ul class="weekly-list">
    @foreach ($report['team_risks'] as $risk)<li>{{ $risk }}</li>@endforeach
  </ul>
</div>
@endif

@if ($mine_empty)
<p class="empty">{{ __('weeklies.pdf.mine_empty') }}</p>
@endif

@foreach ($clients as $client)
<section class="weekly-client">
  <h2>{{ $client['name'] }} <span class="weekly-status weekly-status--{{ $client['status'] }}">{{ $client['status_label'] }}</span></h2>
  @if ($client['satisfaction'] !== null)
  <p class="lead">{{ __('weeklies.pdf.satisfaction', ['score' => $client['satisfaction']]) }}</p>
  @endif
  <p class="weekly-text">{{ $client['summary'] }}</p>

  <div class="weekly-columns">
    <div>
      <h3>{{ __('weeklies.pdf.next_steps') }}</h3>
      @if ($client['next_steps'] === [])
      <p class="empty">{{ __('weeklies.pdf.no_next_steps') }}</p>
      @else
      <ul class="weekly-list">@foreach ($client['next_steps'] as $step)<li>{{ $step }}</li>@endforeach</ul>
      @endif
    </div>
    <div>
      <h3>{{ __('weeklies.pdf.milestones') }}</h3>
      @if ($client['milestones'] === [])
      <p class="empty">{{ __('weeklies.pdf.no_milestones') }}</p>
      @else
      <ul class="weekly-list">@foreach ($client['milestones'] as $milestone)<li>{{ $milestone }}</li>@endforeach</ul>
      @endif
    </div>
  </div>

  @if ($client['tags'] !== [])
  <p class="weekly-tags">@foreach ($client['tags'] as $tag)<span>{{ $tag }}</span>@endforeach</p>
  @endif

  @if ($client['projects'] !== null)
  <h3>{{ __('weeklies.pdf.projects.title') }}</h3>
  @include('reports.pdf.partials.table', ['table' => $client['projects']])
  @endif
</section>
@endforeach
@endif
@endsection
