<section class="cover">
  {!! $logo !!}
  <div class="cover__body">
    <p class="kicker">{{ $cover['kicker'] }}</p>
    <h1 class="cover__title">{{ $cover['title'] }}</h1>
    <p class="cover__sub">{{ $cover['subtitle'] }}</p>
    <dl class="facts">
      @foreach ($cover['facts'] as [$label, $value])
      <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
      @endforeach
    </dl>
  </div>
  @if ($cover['note'] !== null)
  <p class="note">{{ $cover['note'] }}</p>
  @else
  <div></div>
  @endif
</section>
