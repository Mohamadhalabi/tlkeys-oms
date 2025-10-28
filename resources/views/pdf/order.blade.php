{{-- resources/views/pdf/order.blade.php --}}
@php
    use Illuminate\Support\Str;

    /* ================= PRECISION HELPERS (half-up) ================= */
    // round half-up to 2dp or 4dp using BCMath
    $r2 = function (string $v) {
        return bccomp($v, '0', 10) >= 0
            ? bcadd($v, '0.005', 2)      // +0.005 then cut to 2dp
            : bcsub($v, '0.005', 2);     // -0.005 then cut to 2dp
    };
    $r4 = function (string $v) {
        return bccomp($v, '0', 10) >= 0
            ? bcadd($v, '0.00005', 4)
            : bcsub($v, '0.00005', 4);
    };
    $mul = fn($a,$b,$s=8) => bcmul((string)$a,(string)$b,$s);
    $add = fn($a,$b,$s=8) => bcadd((string)$a,(string)$b,$s);
    $sub = fn($a,$b,$s=8) => bcsub((string)$a,(string)$b,$s);

    // UI formatters
    $fmt2  = fn($v) => number_format((float)$v, 2, '.', ',');
    $fmtPct = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.');

    // Locale & direction
    $locale = app()->getLocale();
    $isRtl  = ($locale === 'ar');

    // Company block
    $companyName  = $company ?? config('app.name', 'Techno Lock Keys');
    $companyAddr1 = $company_addr1 ?? 'Industrial Area 5, Maleha Street';
    $companyAddr2 = $company_addr2 ?? 'United Arab Emirates – Sharjah';
    $companyPhone = $company_phone ?? '(+971) 50 442 9045';
    $companyEmail = $company_email ?? 'info@tlkeys.com';
    $companyWeb   = $company_web   ?? 'www.tlkeys.com';

    $branchCode   = $order->branch?->code ?? null;
    $showCompanyBlock = ($branchCode === 'AE');

    // Codes / dates
    $docCode    = $order->code ?? ('TLO' . str_pad($order->id, 6, '0', STR_PAD_LEFT));
    $docDate    = $order->created_at?->format('d.m.Y H:i');
    $customerId = $order->customer?->code ?? ($order->customer_id ? ('TLK' . $order->customer_id) : '-');

    // Currency context
    $rate = (string) max(1.0, (float)($order->exchange_rate ?? 1));   // USD -> currency
    $cur  = $order->currency ?: 'USD';
    $fx   = fn(string $usd) => $r2($mul($usd, $rate, 8));              // convert then round 2dp
    $brandColor = '#2D83B0';

    // Customer
    $customer   = $order->customer;
    $custName   = $customer->name ?? '-';
    $custEmail  = $customer->email ?? '';
    $custPhone  = $customer->phone ?? '';
    $custAddr   = trim(($customer->address ?? ''));
    $custCity   = trim(($customer->city ?? ''));
    $custCountry= trim(($customer->country ?? ''));
    $custPostal = trim(($customer->postal_code ?? ''));

    $hasCustomerData = $customer && (
        ($custName && $custName !== '-') ||
        $custPhone || $custEmail || $custAddr || $custCity || $custCountry || $custPostal
    );

    // Status
    $status       = strtolower((string)$order->payment_status);
    $statusPretty = str_replace('_', ' ', $status);

    // Logo (absolute "file://..." was prepared in controller)
    $logo = $logoPath ?: null;

    // Localized product title
    $pickTitle = function ($p, $fallback = '') use ($locale) {
        if (is_array($p?->title ?? null)) {
            return $p->title[$locale] ?? $p->title['en'] ?? reset($p->title) ?? $fallback;
        }
        return $p->title ?? $fallback;
    };

    /* =================== RECOMPUTE MONEY IN USD ==================== */
    // We DO NOT trust DB line_total/subtotal/total here (they may be pre-rounded).
    // Build precise USD sums from qty * unit_price.
    $linesUsd = '0.00000000';
    foreach ($order->items as $row) {
        $line = $mul((string)$row->qty, (string)$row->unit_price, 8); // exact qty*unit_price (USD)
        $linesUsd = $add($linesUsd, $line, 8);
        // stash per-row computed USD for table
        $row->__pdf_line_usd = $line;
    }

    // Other amounts in USD pulled from order (already USD)
    $discountUsd = (string) ($order->discount ?? 0);
    $shippingUsd = (string) ($order->shipping ?? 0);

    // Optional % service fee (legacy)
    $feesPct     = isset($order->service_fees_percent) ? (float)$order->service_fees_percent : 0.0;
    $feesUsd     = '0';
    if ($feesPct > 0) {
        $base = $add($sub($linesUsd, $discountUsd, 8), $shippingUsd, 8);
        $feesUsd = $mul($base, (string)($feesPct/100), 8);
    }

    // Flat extra fees in USD
    $extraFeesUsd = (string) ($order->extra_fees ?? 0);

    // Subtotal / Grand total (USD, precise)
    $subtotalUsd  = $linesUsd; // items only
    $grandUsd     = $add(
                        $add(
                            $sub($subtotalUsd, $discountUsd, 8),
                            $shippingUsd, 8
                        ),
                        $add($feesUsd, $extraFeesUsd, 8),
                        8
                    );

    $paidUsd      = (string) ($order->paid_amount ?? 0);
    $dueUsd       = bccomp($grandUsd, $paidUsd, 8) > 0 ? $sub($grandUsd, $paidUsd, 8) : '0';

@endphp

<!doctype html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ $companyName }} - {{ __('Invoice') }} {{ $docCode }}</title>
<style>
@if ($isRtl) * { font-family: Amiri, "DejaVu Sans", sans-serif; } @else * { font-family: "DejaVu Sans", sans-serif; } @endif
.bidi { unicode-bidi: plaintext; }

@if($showCompanyBlock) @page { margin: 28px 28px 70px 28px; }
@else                  @page { margin: 28px; } @endif

body { font-size: 13px; color: #111; }
.brand { color: {{ $brandColor }}; }
.muted { color: #666; }

.header { width: 100%; }
.header::after { content:""; display:block; clear: both; }
.header .left  { float: left;  width: 60%; }
.header .right { float: right; width: 40%; text-align: right; }
[dir="rtl"] .header .left  { float: right; text-align: right; }
[dir="rtl"] .header .right { float: left;  text-align: left; }

.logo { height: 50px; display:block; margin-bottom: 6px; }
.rule { height: 2px; background: {{ $brandColor }}; opacity: .15; margin: 8px 0 14px; }

table { width: 100%; border-collapse: collapse; }
th, td { padding: 8px 10px; border: 1px solid #e5e7eb; vertical-align: middle; }
th { background: #f8fafc; font-weight: 700; text-align: left; }
[dir="rtl"] th, [dir="rtl"] td { text-align: right; }
.sku { font-weight: 700; white-space: nowrap; }
.qty { text-align: center; width: 48px; }
.thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 6px; display: inline-block; vertical-align: middle; }

.badge { display:inline-block; padding:3px 12px; border-radius:999px; background:#eef2ff; color:#374151; border:1px solid #e5e7eb; font-size:12px; }

.totalsbox { width: 380px; margin-left: auto; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 6px; }
.totalsbox td { padding: 9px 12px; border-bottom: 1px solid #eef2f7; }
.totalsbox tr:last-child td { border-bottom: 0; }
.totalsbox .val { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
[dir="rtl"] .totalsbox .val { text-align: left; }
.totalsbox .grand td { background: #fee2e2; }
.totalsbox .grand .val { color: #dc2626; font-weight: 800; font-size: 14px; }

.footer { position: fixed; left:0; right:0; bottom: -2px; height: 54px; border-top: 1px solid #e5e7eb; padding: 6px 12px 0 12px; font-size: 10px; color: #6b7280; }
.footer .left { float: left; } .footer .right { float: right; text-align: right; }
[dir="rtl"] .footer .left { float: right; text-align: right; } [dir="rtl"] .footer .right { float: left; text-align: left; }
</style>
</head>
<body>

<div class="header">
  <div class="left">
    @if($showCompanyBlock)
      @if($logo)
        <img class="logo" src="{{ $logo }}">
      @endif
      <div class="muted">
        {{ $companyAddr1 }} <br>
        {{ $companyAddr2 }} <br>
        {{ __('Tel') }}: {{ $companyPhone }} <br>
        {{ __('Email') }}: {{ $companyEmail }}
      </div>
    @endif
  </div>
  <div class="right">
    <div><b>{{ __('Date') }}: {{ $docDate }}</b></div>
    <div><b>{{ __('Order Number') }}: #{{ $docCode }}</b></div>
    <div><b>{{ __('Customer ID') }}: {{ $customerId }}</b></div>
    @if($statusPretty)
      <div class="badge" style="margin-top:6px;">{{ __($statusPretty) }}</div>
    @endif
  </div>
</div>

<div class="rule"></div>

@if($hasCustomerData)
<table style="margin-bottom:12px;">
  <tr><td style="border:1px solid #e5e7eb;">
    <span class="brand">{{ __('Customer Information') }}</span><br><br>
    <table style="width:100%; border-collapse:separate;">
      @if($custName && $custName !== '-')
      <tr><td style="border:none; padding:0 6px 2px 0;"><strong>{{ __('Name') }}:</strong></td><td style="border:none; padding:0 0 2px 0;">{{ $custName }}</td></tr>
      @endif
      @if($custPhone)
      <tr><td style="border:none; padding:0 6px 0 0;"><strong>{{ __('Phone') }}:</strong></td><td style="border:none; padding:0;">{{ $custPhone }}</td></tr>
      @endif
      @if($custEmail)
      <tr><td style="border:none; padding:0 6px 0 0;"><strong>{{ __('Email') }}:</strong></td><td style="border:none; padding:0;">{{ $custEmail }}</td></tr>
      @endif
      @if($custAddr || $custCity || $custCountry || $custPostal)
      <tr><td style="border:none; padding:0 6px 0 0;"><strong>{{ __('Address') }}:</strong></td>
          <td style="border:none; padding:0;">
            {{ $custAddr }}@if($custCity), {{ $custCity }}@endif
            @if($custCountry), {{ $custCountry }}@endif
            @if($custPostal), {{ $custPostal }}@endif
          </td></tr>
      @endif
    </table>
  </td></tr>
</table>
@endif

<h4 class="brand" style="margin:10px 0 6px;">{{ __('Items') }}</h4>
<table class="items">
  <thead>
    <tr>
      <th style="width:32px;text-align:center;">#</th>
      <th style="width:64px;">{{ __('Image') }}</th>
      <th>{{ __('Product') }}</th>
      <th class="sku">{{ __('SKU') }}</th>
      <th class="qty">{{ __('Qty') }}</th>
      <th class="right">{{ __('Unit Price') }}</th>
      <th class="right">{{ __('Total') }}</th>
    </tr>
  </thead>
  <tbody>
    @foreach($order->items as $i => $row)
      @php
        $p     = $row->product;
        $sku   = $p->sku ?? ($row->sku ?? '-');
        $src   = $imgMap[$row->product_id] ?? null;
        $title = $pickTitle($p, $row->title ?? '');

        // ALWAYS compute line from qty * unit_price in USD (ignore DB line_total)
        $lineUsd = $row->__pdf_line_usd ?? '0';
        $unitFx  = $fx((string)$row->unit_price);   // converted & rounded 2dp
        $lineFx  = $fx($lineUsd);                   // converted & rounded 2dp
      @endphp
      <tr>
        <td style="text-align:center;">{{ $i + 1 }}</td>
        <td>@if($src)<img class="thumb" src="{{ $src }}" alt="{{ $title }}">@endif</td>
        <td><div style="font-weight:600;margin-bottom:2px;">{{ $title }}</div></td>
        <td class="sku">{{ $sku ?: '—' }}</td>
        <td class="qty">{{ (int)$row->qty }}</td>
        <td class="right"><span>{{ $cur }} {{ $fmt2($unitFx) }}</span></td>
        <td class="right"><span>{{ $cur }} {{ $fmt2($lineFx) }}</span></td>
      </tr>
    @endforeach
  </tbody>
</table>

@php
    $subtotalFx = $fx($subtotalUsd);
    $discountFx = $fx($discountUsd);
    $shippingFx = $fx($shippingUsd);
    $feesFx     = $fx($feesUsd);
    $extraFx    = $fx($extraFeesUsd);
    $grandFx    = $fx($grandUsd);
    $paidFx     = $fx($paidUsd);
    $dueFx      = $fx($dueUsd);
@endphp

<div class="totalsbox">
  <table>
    <tr>
      <td class="label">{{ __('Subtotal') }}</td>
      <td class="val"><span class="bidi">{{ $cur }} {{ $fmt2($subtotalFx) }}</span></td>
    </tr>
    @if(bccomp($discountUsd,'0',8) > 0)
    <tr>
      <td class="label">{{ __('Discount') }}</td>
      <td class="val">− <span class="bidi">{{ $cur }} {{ $fmt2($discountFx) }}</span></td>
    </tr>
    @endif
    <tr>
      <td class="label">{{ __('Shipping') }}</td>
      <td class="val"><span class="bidi">{{ $cur }} {{ $fmt2($shippingFx) }}</span></td>
    </tr>
    @if($feesPct > 0)
    <tr>
      <td class="label">{{ __('Service Fees (:pct%)', ['pct' => $fmtPct($feesPct)]) }}</td>
      <td class="val"><span class="bidi">{{ $cur }} {{ $fmt2($feesFx) }}</span></td>
    </tr>
    @endif
    @if(bccomp($extraFeesUsd,'0',8) > 0)
    <tr>
      <td class="label">{{ __('Extra Fees') }}</td>
      <td class="val"><span class="bidi">{{ $cur }} {{ $fmt2($extraFx) }}</span></td>
    </tr>
    @endif
    <tr class="grand">
      <td class="label">{{ __('Total') }}</td>
      <td class="val"><span class="bidi">{{ $cur }} {{ $fmt2($grandFx) }}</span></td>
    </tr>
    @if(bccomp($paidUsd,'0',8) > 0)
    <tr>
      <td class="label">{{ __('Paid') }}</td>
      <td class="val"><span class="bidi">{{ $cur }} {{ $fmt2($paidFx) }}</span></td>
    </tr>
    <tr>
      <td class="label">{{ __('Due') }}</td>
      <td class="val"><span class="bidi">{{ $cur }} {{ $fmt2($dueFx) }}</span></td>
    </tr>
    @endif
  </table>
</div>

@if($showCompanyBlock)
<div class="footer">
  <div class="left"><strong>{{ $companyName }}</strong> — {{ $companyAddr1 }}, {{ $companyAddr2 }}</div>
  <div class="right">{{ $companyWeb }} • {{ $companyEmail }} • {{ $companyPhone }}</div>
</div>
@endif

{{-- mPDF page numbers --}}
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont("DejaVu Sans", "normal");
    $size = 9;
    $text = "{{ __('Page :n of :c') }}";
    $text = str_replace([':n', ':c'], ['{PAGENO}', '{nbpg}'], $text);
    $w = $fontMetrics->get_text_width($text, $font, $size);
    $x = 297 - ($w / 2);
    $y = {{ $showCompanyBlock ? 825 : 812 }};
    $pdf->SetFont("DejaVu Sans", "", 9);
    $pdf->text(28, $y, "{{ $docCode }}");
    $pdf->text(530, $y, "{{ $docDate }}");
    $pdf->text($x, $y, $text);
}
</script>
</body>
</html>
