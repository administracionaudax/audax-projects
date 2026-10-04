<section class="definitions">
  <h2>{{ __('report_pdf.definitions') }}</h2>
  <dl>
    @foreach ($definitions as [$label, $definition])
    <dt>{{ $label }}</dt><dd>{{ $definition }}</dd>
    @endforeach
  </dl>
</section>
