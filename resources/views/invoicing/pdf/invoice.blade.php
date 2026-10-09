{{-- Factura o rectificativa propia (PLAN-EMISION E1; L-01 a L-10 y L-17; G-4, D-426): hoja de
     documentos de Audax (ReportHtml::theme) en A4 vertical, DM Sans 400/500. Arriba y centrado queda
     libre el hueco del QR de VeriFactu (E7, V-12). Un borrador lleva la marca «Borrador» y no tiene
     número. Los textos, en el idioma del cliente (lang/es/invoicing.php, pdf.es y pdf.en). --}}
<!doctype html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title }}</title>
<style>{!! $theme !!}</style>
<style>
@page{margin:16mm 16mm 20mm;@top-right{content:none;}@bottom-left{content:"{{ str_replace(['\\', '"'], ['\\\\', '\\"'], $title) }}";font-family:"DM Sans",sans-serif;font-size:7pt;color:#8C99AC;vertical-align:top;padding-top:4mm;}}
html{font-size:9pt;}
b,strong,h3,h4{font-weight:500;}
.inv-head{display:grid;grid-template-columns:1fr 40mm 1fr;align-items:start;gap:6mm;margin-bottom:8mm;}
.inv-head .logo{height:24px;width:auto;max-width:60mm;}
.inv-head .logo--custom{height:auto;max-height:18mm;}
.inv-qr{height:40mm;}
.inv-issuer{text-align:right;font-size:8pt;line-height:1.5;color:var(--muted);}
.inv-issuer .inv-name{font-size:9.5pt;color:var(--ink);font-weight:500;}
.inv-title{display:flex;justify-content:space-between;align-items:flex-end;gap:6mm;padding-bottom:6pt;border-bottom:2px solid var(--blue);margin-bottom:10pt;}
.inv-title h1{font-size:20pt;font-weight:400;letter-spacing:-.02em;line-height:1.1;}
.inv-title .inv-number{font-size:14pt;font-weight:400;color:var(--blue);white-space:nowrap;}
.inv-parties{display:grid;grid-template-columns:1fr 62mm;gap:8mm;margin-bottom:10pt;}
.inv-box{padding:8pt 10pt;border:1px solid var(--line);}
.inv-label{font-size:7pt;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);margin-bottom:3pt;}
.inv-dates{display:grid;gap:3pt;font-size:8.5pt;}
.inv-dates div{display:flex;justify-content:space-between;gap:4mm;}
.inv-dates dt{color:var(--muted);}
.inv-rect{margin:0 0 10pt;}
table.inv-lines td:first-child{font-weight:400;}
table.inv-lines .num,table.inv-taxes .num{text-align:right;white-space:nowrap;}
table.inv-lines th.num,table.inv-taxes th.num{text-align:right;}
.inv-desc{display:block;color:var(--muted);font-size:8pt;white-space:pre-line;}
.inv-code{color:var(--faint);font-size:7.5pt;}
.inv-text-line td{color:var(--muted);font-style:italic;}
.inv-bottom{display:grid;grid-template-columns:1fr 70mm;gap:8mm;align-items:start;break-inside:avoid;}
.inv-sums{display:grid;gap:3pt;font-size:9pt;}
.inv-sums div{display:flex;justify-content:space-between;gap:4mm;}
.inv-sums dt{color:var(--muted);}
.inv-sums .inv-total{border-top:1px solid var(--ink);padding-top:5pt;margin-top:3pt;font-size:12pt;}
.inv-sums .inv-total dt{color:var(--ink);font-weight:500;}
.inv-mentions{margin-top:10pt;font-size:8pt;}
.inv-body{margin-top:10pt;white-space:pre-line;font-size:8.5pt;}
.inv-footer{margin-top:14pt;padding-top:6pt;border-top:1px solid var(--line);font-size:7pt;color:var(--muted);white-space:pre-line;}
.inv-mark{position:fixed;top:95mm;left:0;right:0;text-align:center;font-size:72pt;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:rgba(1,113,255,.08);transform:rotate(-24deg);pointer-events:none;}
@media screen{body{background:#fff;} .inv-mark{position:absolute;}}
</style>
</head>
<body class="invoice">
@if ($draft)
<div class="inv-mark" aria-hidden="true">{{ $t('draft_mark') }}</div>
@elseif ($test)
<div class="inv-mark" aria-hidden="true">{{ $t('test_mark') }}</div>
@endif

<header class="inv-head">
  <div>{!! $logo !!}</div>
  {{-- Hueco del QR de VeriFactu (V-12): en E1 va vacío, sin QR ni leyenda. --}}
  <div class="inv-qr" aria-hidden="true"></div>
  <div class="inv-issuer">
    <div class="inv-name">{{ $issuer['legal_name'] ?? '' }}@if (! empty($issuer['legal_form']) && ! str_contains((string) ($issuer['legal_name'] ?? ''), (string) $issuer['legal_form'])) {{ $issuer['legal_form'] }}@endif</div>
    @if (! empty($issuer['trade_name']))<div>{{ $issuer['trade_name'] }}</div>@endif
    <div>{{ $t('tax_id') }} {{ $issuer['tax_id'] ?? '' }}</div>
    <div>{{ $issuer['address'] ?? '' }}</div>
    <div>{{ trim(($issuer['postal_code'] ?? '').' '.($issuer['city'] ?? '')) }}@if (! empty($issuer['province']) && $issuer['province'] !== ($issuer['city'] ?? null)) ({{ $issuer['province'] }})@endif{{ $country($issuer['country_code'] ?? null) !== '' ? ' · '.$issuer['country_code'] : '' }}</div>
    @if (! empty($issuer['email']) || ! empty($issuer['phone']))<div>{{ implode(' · ', array_filter([$issuer['email'] ?? null, $issuer['phone'] ?? null])) }}</div>@endif
    @if (! empty($issuer['website']))<div>{{ $issuer['website'] }}</div>@endif
  </div>
</header>

<div class="inv-title">
  <h1>{{ $heading }}</h1>
  <div class="inv-number">{{ $number }}</div>
</div>

<section class="inv-parties">
  <div class="inv-box">
    <div class="inv-label">{{ $t('client') }}</div>
    <div><strong>{{ $client['legal_name'] ?? $client['name'] ?? '' }}</strong></div>
    @if (! empty($client['tax_id']))<div>{{ $t('tax_id') }} {{ $client['tax_id'] }}</div>@endif
    @if (! empty($client['eu_vat_number']))<div>{{ $t('vat_number') }} {{ $client['eu_vat_number'] }}</div>@endif
    @if (! empty($client['address']))<div>{{ $client['address'] }}</div>@endif
    <div>{{ trim(($client['postal_code'] ?? '').' '.($client['city'] ?? '')) }}@if (! empty($client['province']) && $client['province'] !== ($client['city'] ?? null)) ({{ $client['province'] }})@endif{{ $country($client['country_code'] ?? null) !== '' ? ' · '.$client['country_code'] : '' }}</div>
  </div>
  <dl class="inv-box inv-dates">
    @foreach ($dates as [$label, $value])
    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
    @endforeach
  </dl>
</section>

@if ($rectified)
<p class="note inv-rect">{{ $t('rectifies', ['number' => $rectified['number'], 'date' => $rectified['date']]) }} {{ $rectified['kind'] }}. {{ $t('reason') }}: {{ $rectified['reason'] }}</p>
@endif

<table class="inv-lines">
  <thead>
    <tr>
      <th>{{ $t('concept') }}</th>
      <th class="num">{{ $t('quantity') }}</th>
      <th class="num">{{ $t('price') }}</th>
      <th class="num">{{ $t('discount') }}</th>
      <th class="num">{{ $t('tax') }}</th>
      <th class="num">{{ $t('base') }}</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($lines as $line)
    @if ($line['kind'] === 'text')
    <tr class="inv-text-line"><td colspan="6">{{ $line['description'] }}</td></tr>
    @else
    <tr>
      <td>
        {{ $line['name'] ?? $line['description'] }}@if ($line['code']) <span class="inv-code">· {{ $line['code'] }}</span>@endif
        @if ($line['name'] && $line['description'])<span class="inv-desc">{{ $line['description'] }}</span>@endif
      </td>
      <td class="num">{{ $line['quantity'] }} {{ $line['unit'] }}</td>
      <td class="num">{{ $line['price'] }}</td>
      <td class="num">{{ $line['discount'] }}</td>
      <td class="num">{{ $line['tax'] }}</td>
      <td class="num">{{ $line['base'] }}</td>
    </tr>
    @endif
    @endforeach
  </tbody>
</table>

<section class="inv-bottom">
  <div>
    <table class="inv-taxes">
      <thead>
        <tr><th>{{ $t('tax_breakdown') }}</th><th class="num">{{ $t('base') }}</th><th class="num">{{ $t('tax_amount') }}</th></tr>
      </thead>
      <tbody>
        @foreach ($taxes as $tax)
        <tr><td>{{ $tax['label'] }}</td><td class="num">{{ $tax['base'] }}</td><td class="num">{{ $tax['tax'] }}</td></tr>
        @endforeach
      </tbody>
    </table>
    @if ($payment || $iban)
    <div class="inv-label">{{ $t('payment') }}</div>
    <p>{{ $payment ?? $t('transfer_to', ['iban' => $iban]) }}</p>
    @endif
  </div>
  <dl class="inv-sums">
    <div><dt>{{ $t('subtotal') }}</dt><dd>{{ $sums['subtotal'] }}</dd></div>
    @if ($sums['discount'])<div><dt>{{ $t('discounts_included') }}</dt><dd>{{ $sums['discount'] }}</dd></div>@endif
    <div><dt>{{ $t('tax_total') }}</dt><dd>{{ $sums['tax'] }}</dd></div>
    @if ($sums['withholding'])<div><dt>{{ $t('withholding', ['rate' => $sums['withholding_rate']]) }}</dt><dd>{{ $sums['withholding'] }}</dd></div>@endif
    <div class="inv-total"><dt>{{ $t('total') }}</dt><dd>{{ $sums['total'] }}</dd></div>
  </dl>
</section>

@if ($mentions !== [])
<div class="inv-mentions">
  @foreach ($mentions as $mention)
  <p>{{ $mention }}</p>
  @endforeach
</div>
@endif

@if ($body)
<div class="inv-body">{{ $body }}</div>
@endif

<footer class="inv-footer">{{ implode("\n", array_filter([$issuer['registry'] ?? null, $footer, $legal_text])) }}</footer>
</body>
</html>
