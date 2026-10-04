@if ($kpis !== [])
<dl class="kpis">
  @foreach ($kpis as $kpi)
  <div class="kpi">
    <dt>{{ $kpi['label'] }}</dt>
    <dd>{{ $kpi['value'] }}</dd>
    @if ($kpi['detail'] !== null)<p>{{ $kpi['detail'] }}</p>@endif
  </div>
  @endforeach
</dl>
@endif
