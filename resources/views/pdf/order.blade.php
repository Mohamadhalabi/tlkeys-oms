@php
    use Illuminate\Support\Str;

    // Locale & direction
    $locale = app()->getLocale();
    $isRtl  = ($locale === 'ar');

    // Formatters
    $fmt = fn($v) => number_format((float) $v, 2, '.', ',');
    $fmtPct = function ($v) { $s = number_format((float)$v, 2, '.', ','); return rtrim(rtrim($s, '0'), '.'); };

    // Build mPDF-friendly path helper (keeps file://, data:, absolute, storage/public)
    $toPath = function (?string $path) {
        if (!$path) return null;
        if (Str::startsWith($path, ['data:', 'http://', 'https://', '/', 'file://'])) return $path;
        $relative = Str::startsWith($path, 'storage/') ? $path : ('storage/' . ltrim($path, '/'));
        return 'file://' . public_path($relative);
    };

    // Business / branch
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

    // Snapshot FX context (display only; DB stays USD)
    $rate = max(1.0, (float)($order->exchange_rate ?? 1));
    $cur  = $order->currency ?: 'USD';
    $fmtFx = fn($usd) => $fmt(((float)$usd) * $rate);

    // Money in USD from DB
    $subtotal   = (float)($order->subtotal ?? 0);
    $discount   = (float)($order->discount ?? 0);
    $shipping   = (float)($order->shipping ?? 0);

    // If you still support a % service fee (legacy)
    $feesPct    = isset($order->service_fees_percent) ? (float)$order->service_fees_percent : 0;
    $feesVal    = $feesPct ? round(($subtotal - $discount + $shipping) * ($feesPct/100), 2) : 0;

    // NEW: explicit extra fees (flat amount in USD, already included in $order->total by your backend)
    $extraFees  = (float)($order->extra_fees ?? 0);

    $grandTotal = (float)($order->total ?? ($subtotal - $discount + $shipping + $feesVal + $extraFees));
    $paid       = (float)($order->paid_amount ?? 0);
    $due        = max($grandTotal - $paid, 0);

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

    // Logo (already absolute "file://..." from controller)
    $logo = $logoPath ? $logoPath : null;

    $brandColor = '#2D83B0';

    // Helper to pick localized product title if it's an array
    $pickTitle = function ($p, $fallback = '') use ($locale) {
        if (is_array($p?->title ?? null)) {
            return $p->title[$locale] ?? $p->title['en'] ?? reset($p->title) ?? $fallback;
        }
        return $p->title ?? $fallback;
    };
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
                    <img class="logo" width="" src="https://dev-srv.tlkeys.com/storage/Logo/techno-lock-desktop-logo.jpg">
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
        <tr>
            <td style="border:1px solid #e5e7eb;">
                <span class="brand">{{ __('Customer Information') }}</span><br><br>
                <table style="width: 100%; border-collapse: separate;">
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
            </td>
        </tr>
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
                    $src   = $imgMap[$row->product_id] ?? null; // already "file://..." or data:
                    $title = $pickTitle($p, $row->title ?? '');
                    $line  = $row->line_total ?? ($row->qty * $row->unit_price); // USD
                @endphp
                <tr>
                    <td style="text-align:center;">{{ $i + 1 }}</td>
                    <td>
                        @if($src)
                            <img class="thumb" src="{{ $src }}" alt="{{ $title }}">
                        @endif
                    </td>
                    <td><div style="font-weight:600;margin-bottom:2px;">{{ $title }}</div></td>
                    <td class="sku">{{ $sku ?: '—' }}</td>
                    <td class="qty">{{ (int)$row->qty }}</td>
                    <td class="right"><span class="">{{ $cur }} {{ $fmtFx($row->unit_price) }}</span></td>
                    <td class="right"><span class="">{{ $cur }} {{ $fmtFx($line) }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totalsbox">
        <table>
            <tr>
                <td class="label">{{ __('Subtotal') }}</td>
                <td class="val"><span class="bidi">{{ $cur }} {{ $fmtFx($subtotal) }}</span></td>
            </tr>
            @if($discount > 0)
            <tr>
                <td class="label">{{ __('Discount') }}</td>
                <td class="val">− <span class="bidi">{{ $cur }} {{ $fmtFx($discount) }}</span></td>
            </tr>
            @endif
            <tr>
                <td class="label">{{ __('Shipping') }}</td>
                <td class="val"><span class="bidi">{{ $cur }} {{ $fmtFx($shipping) }}</span></td>
            </tr>
            @if($feesPct > 0)
            <tr>
                <td class="label">{{ __('Service Fees (:pct%)', ['pct' => $fmtPct($feesPct)]) }}</td>
                <td class="val"><span class="bidi">{{ $cur }} {{ $fmtFx($feesVal) }}</span></td>
            </tr>
            @endif

            {{-- NEW: show Extra Fees only when non-zero --}}
            @if($extraFees > 0)
            <tr>
                <td class="label">{{ __('Extra Fees') }}</td>
                <td class="val"><span class="bidi">{{ $cur }} {{ $fmtFx($extraFees) }}</span></td>
            </tr>
            @endif

            <tr class="grand">
                <td class="label">{{ __('Total') }}</td>
                <td class="val"><span class="bidi">{{ $cur }} {{ $fmtFx($grandTotal) }}</span></td>
            </tr>
            @if($paid > 0)
            <tr>
                <td class="label">{{ __('Paid') }}</td>
                <td class="val"><span class="bidi">{{ $cur }} {{ $fmtFx($paid) }}</span></td>
            </tr>
            <tr>
                <td class="label">{{ __('Due') }}</td>
                <td class="val"><span class="bidi">{{ $cur }} {{ $fmtFx($due) }}</span></td>
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
