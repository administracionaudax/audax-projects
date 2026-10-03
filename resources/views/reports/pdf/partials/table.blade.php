{{-- Tabla de PdfTable::make(): cabecera gris del kit, números a la derecha y fila de totales. --}}
@if ($table['rows'] === [] && $table['empty'] !== null)
<p class="empty">{{ $table['empty'] }}</p>
@else
<table @class(['compact' => $table['compact']])>
  <thead><tr>
    @foreach ($table['columns'] as $column)<th @class(['num' => $column['num']])>{{ $column['label'] }}</th>@endforeach
  </tr></thead>
  <tbody>
    @foreach ($table['rows'] as $row)
    <tr>@foreach ($row as $i => $cell)<td @class(['num' => $table['columns'][$i]['num'] ?? false, is_array($cell) ? $cell['class'] : ''])>{{ is_array($cell) ? $cell['text'] : $cell }}</td>@endforeach</tr>
    @endforeach
    @if ($table['sum'] !== null)
    <tr class="sum">@foreach ($table['sum'] as $i => $cell)<td @class(['num' => $table['columns'][$i]['num'] ?? false, is_array($cell) ? $cell['class'] : ''])>{{ is_array($cell) ? $cell['text'] : $cell }}</td>@endforeach</tr>
    @endif
  </tbody>
</table>
@endif
