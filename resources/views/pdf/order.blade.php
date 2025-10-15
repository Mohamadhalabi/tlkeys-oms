@php
    use Illuminate\Support\Str;

    // Locale & direction
    $locale = app()->getLocale();
    $isRtl  = ($locale === 'ar');

    // Money formatter: 1,234.56
    $fmt = fn($v) => number_format((float) $v, 2, '.', ',');

    // Percent formatter without trailing zeros
    $fmtPct = function ($v) {
        $s = number_format((float)$v, 2, '.', ',');
        return rtrim(rtrim($s, '0'), '.');
    };

    // Build a Dompdf/mPDF-friendly local/public path for images
    $toPath = function (?string $path) {
        if (!$path) return null;
        if (Str::startsWith($path, ['data:', 'http://', 'https://', '/', 'file://'])) return $path;
        $relative = Str::startsWith($path, 'storage/') ? $path : ('storage/' . ltrim($path, '/'));
        return public_path($relative);
    };

    // Business / branch
    $companyName  = $company ?? config('app.name', 'Techno Lock Keys');
    $companyAddr1 = $company_addr1 ?? 'Industrial Area 5, Maleha Street';
    $companyAddr2 = $company_addr2 ?? 'United Arab Emirates – Sharjah';
    $companyPhone = $company_phone ?? '(+971) 50 442 9045';
    $companyEmail = $company_email ?? 'info@tlkeys.com';
    $companyWeb   = $company_web   ?? 'www.tlkeys.com';

    $branchCode   = $order->branch?->code ?? null;
    $showCompanyBlock = ($branchCode === 'AE');  // 🔹 Only show company header/footer for AE

    // Codes / dates
    $docCode    = $order->code ?? ('TLO' . str_pad($order->id, 6, '0', STR_PAD_LEFT));
    $docDate    = $order->created_at?->format('d.m.Y H:i');
    $customerId = $order->customer?->code ?? ($order->customer_id ? ('TLK' . $order->customer_id) : '-');

    // Money
    $subtotal   = (float)($order->subtotal ?? 0);
    $discount   = (float)($order->discount ?? 0);
    $shipping   = (float)($order->shipping ?? 0);
    $feesPct    = isset($order->service_fees_percent) ? (float)$order->service_fees_percent : 0;
    $feesVal    = $feesPct ? round(($subtotal - $discount + $shipping) * ($feesPct/100), 2) : 0;
    $grandTotal = (float)($order->total ?? ($subtotal - $discount + $shipping + $feesVal));
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

    // Determine if customer block has meaningful data
    $hasCustomerData = $customer && (
        ($custName && $custName !== '-') ||
        $custPhone || $custEmail || $custAddr || $custCity || $custCountry || $custPostal
    );

    // Status
    $status       = strtolower((string)$order->payment_status);
    $statusPretty = str_replace('_', ' ', $status); // translate as raw words

    // Logo path
    $logo       = !empty($logoPath) ? $toPath($logoPath) : null;
    $brandColor = '#2D83B0';

    // Hard-coded QR (optional)
    $qr = file_exists(public_path('images/qr-code.png')) ? public_path('images/qr-code.png') : null;

    // Helper to pick localized product title if it's an array: ['en' => ..., 'ar' => ...]
    $pickTitle = function ($p, $fallback = '') use ($locale) {
        $title = $fallback;
        if (is_array($p?->title ?? null)) {
            $title = $p->title[$locale] ?? $p->title['en'] ?? reset($p->title) ?? $fallback;
        } else {
            $title = $p->title ?? $fallback;
        }
        return $title;
    };
@endphp

<!doctype html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $companyName }} - {{ __('Invoice') }} {{ $docCode }}</title>
    <style>
        /* Use Amiri only for Arabic; otherwise DejaVu Sans */
        @if ($isRtl)
        * { font-family: Amiri, "DejaVu Sans", sans-serif; }
        @else
        * { font-family: "DejaVu Sans", sans-serif; }
        @endif

        .bidi { unicode-bidi: plaintext; }  /* keeps USD + numbers readable inside RTL */

        /* Make room for footer only when AE */
        @if($showCompanyBlock)
            @page { margin: 28px 28px 70px 28px; } /* bottom margin bigger for footer */
        @else
            @page { margin: 28px; }                /* no extra bottom space */
        @endif

        body { font-size: 13px; color: #111; }
        h1,h2,h3,h4 { margin: 0; }
        .brand { color: {{ $brandColor }}; }
        .muted { color: #666; }
        .small { font-size: 10px; }
        .xs { font-size: 9px; }

        .header { width: 100%; box-sizing: border-box; }
        .header::after { content:""; display:block; clear: both; }

        .header .left  { float: left;  width: 60%; }
        .header .right { float: right; width: 40%; text-align: right; }

        /* RTL overrides */
        [dir="rtl"] .header .left  { float: right; text-align: right; }
        [dir="rtl"] .header .right { float: left;  text-align: left; }
        [dir="rtl"] th, [dir="rtl"] td { text-align: right; }
        [dir="rtl"] .qty { text-align: center; }
        [dir="rtl"] .right { text-align: left !important; }

        .logo { height: 36px; max-width: 100%; display:block; margin-bottom: 6px; }
        .qr { width: 72px; height: 72px; object-fit: contain; display: inline-block; margin-top: 4px; margin-left: 8px; }

        .rule { height: 2px; background: {{ $brandColor }}; opacity: .15; margin: 8px 0 14px; }

        .panel {
            border: 1.5px solid {{ $brandColor }};
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 12px;
            background: #fff;
            box-sizing: border-box;
        }

        .badge{
            display: inline-block;
            width: 25%;
            float:right;
            text-align:center;
            white-space: nowrap;
            padding: 3px 18px;
            border-radius: 999px;
            background: #eef2ff;
            color: #374151;
            border: 1px solid #e5e7eb;
            font-size: 12px;
            line-height: 1.2;
        }
        [dir="rtl"] .badge { float: left; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 10px; border: 1px solid #e5e7eb; vertical-align: middle; }
        th { background: #f8fafc; font-weight: 700; text-align: left; }
        .sku { font-weight: 700; white-space: nowrap; }
        .qty { text-align: center; width: 48px; }
        .thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 6px; display: inline-block; vertical-align: middle; } /* inline-block helps vertical centering */

        .two-col { width: 100%; border: none; border-collapse: separate; table-layout: fixed; }
        .two-col .col { width: 50%; }
        .two-col .left-pad  { padding-right: 6px; }
        .two-col .right-pad { padding-left: 6px; }

        /* Items table */
        table.items {
            border: 1px solid #e5e7eb;
            page-break-inside: auto;
            margin-bottom: 12px;
        }
        table.items tr { page-break-inside: avoid; page-break-after: auto; }

        /* >>> Ensure *true* vertical centering in the items grid (Dompdf-friendly) */
        table.items th,
        table.items td { vertical-align: middle !important; }
        table.items td > * { vertical-align: middle; } /* inline children align in middle line box */

        /* >>> Striped body rows (print-friendly subtle) */
        table.items tbody tr:nth-child(even) td { background: #f3f7ff; }  /* very light */
        table.items tbody tr:nth-child(odd) td { background: #ffffff; }

        .keep-together { page-break-inside: avoid; }

        /* === Totals box (updated styles) === */
        .totalsbox {
            width: 380px;
            margin-left: auto;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0,0,0,.04);
            box-sizing: border-box;
            margin-top: 6px;
        }
        .totalsbox table { width: 100%; border-collapse: collapse; }
        .totalsbox td { padding: 9px 12px; border-bottom: 1px solid #eef2f7; }
        .totalsbox tr:last-child td { border-bottom: 0; }

        .totalsbox .label { color: #374151; }
        .totalsbox .val   { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        [dir="rtl"] .totalsbox .val { text-align: left; }

        /* Shipping in green */
        .totalsbox .shipping .label { color: #047857; }
        .totalsbox .shipping .val   { color: #059669; font-weight: 700; }

        /* Service fees subtle */
        .totalsbox .fees .label { color: #475569; }
        .totalsbox .fees .val   { color: #334155; }

        /* Discount muted red */
        .totalsbox .discount .label { color: #9f1239; }
        .totalsbox .discount .val   { color: #be123c; }

        /* Grand total highlighted in red row */
        .totalsbox .grand td { background: #fee2e2; }
        .totalsbox .grand .label { color: #991b1b; font-weight: 800; }
        .totalsbox .grand .val   { color: #dc2626; font-weight: 800; font-size: 14px; }

        /* Due (if present) as attention row */
        .totalsbox .due td { background: #fef3c7; }
        .totalsbox .due .label { color: #92400e; font-weight: 700; }
        .totalsbox .due .val   { color: #b45309; font-weight: 800; }

        /* === Footer (only rendered for AE below) === */
        .footer {
            position: fixed;
            left: 0; right: 0;
            bottom: -2px;                 /* Dompdf: keep inside bottom margin */
            height: 54px;
            border-top: 1px solid #e5e7eb;
            padding: 6px 12px 0 12px;
            font-size: 10px;
            color: #6b7280;
        }
        .footer .left { float: left; }
        .footer .right { float: right; text-align: right; }
        [dir="rtl"] .footer .left { float: right; text-align: right; }
        [dir="rtl"] .footer .right { float: left; text-align: left; }
    </style>
</head>
<body>
    <div class="header">
        <div class="left">
            @if($showCompanyBlock)
                @if($logo)
                    <img class="logo" src="{{ $logo }}" alt="{{ __('Logo') }}">
                @endif
                <h2 class="brand">{{ $companyName }}</h2>
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

    {{-- Customer block shown only if a user/customer is actually selected / has data --}}
    @if($hasCustomerData)
    <table class="two-col" style="margin-bottom:12px;">
        <tr>
            <td class="col left-pad">
                <div>
                    <span class="brand">{{ __('Customer Information') }}</span><br><br>
                    <table style="width: 100%; border-collapse: separate;">
                        @if($custName && $custName !== '-')
                        <tr>
                            <td style="border:none; padding: 0 6px 2px 0;"><strong>{{ __('Name') }}:</strong></td>
                            <td style="border:none; padding: 0 0 2px 0;">{{ $custName }}</td>
                        </tr>
                        @endif

                        @if($custPhone)
                        <tr>
                            <td style="border:none; padding: 0 6px 0 0;"><strong>{{ __('Phone') }}:</strong></td>
                            <td style="border:none; padding: 0;">{{ $custPhone }}</td>
                        </tr>
                        @endif

                        @if($custEmail)
                        <tr>
                            <td style="border:none; padding: 0 6px 0 0;"><strong>{{ __('Email') }}:</strong></td>
                            <td style="border:none; padding: 0;">{{ $custEmail }}</td>
                        </tr>
                        @endif

                        @if($custAddr || $custCity || $custCountry || $custPostal)
                        <tr>
                            <td style="border:none; padding: 0 6px 0 0;"><strong>{{ __('Address') }}:</strong></td>
                            <td style="border:none; padding: 0;">
                                {{ $custAddr }}
                                @if($custCity), {{ $custCity }}@endif
                                @if($custCountry), {{ $custCountry }}@endif
                                @if($custPostal), {{ $custPostal }}@endif
                            </td>
                        </tr>
                        @endif
                    </table>
                </div>
            </td>
        </tr>
    </table>
    @endif

    <h4 class="brand" style="margin: 10px 0 6px;">{{ __('Items') }}</h4>
    <table class="items">
        <thead>
            <tr>
                <th style="width:32px; text-align:center;">#</th>
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
                    $line  = $row->line_total ?? ($row->qty * $row->unit_price);
                @endphp
                <tr>
                    <td style="text-align:center;">{{ $i + 1 }}</td>
                    <td>
                        @if($src && $toPath($src))
                            <img class="thumb" src="{{ $toPath($src) }}" alt="{{ $title }}">
                        @endif
                    </td>
                    <td><div style="font-weight:600; margin-bottom:2px;">{{ $title }}</div></td>
                    <td class="sku">{{ $sku ?: '—' }}</td>
                    <td class="qty">{{ (int)$row->qty }}</td>
                    <td class="right"><span class="">{{ $order->currency ?? 'USD' }} {{ $fmt($row->unit_price) }}</span></td>
                    <td class="right"><span class="">{{ $order->currency ?? 'USD' }} {{ $fmt($line) }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- === Totals box (colored) === --}}
    <div class="totalsbox keep-together">
        <table>
            <tr>
                <td class="label">{{ __('Subtotal') }}</td>
                <td class="val"><span class="bidi">{{ $order->currency ?? 'USD' }} {{ $fmt($subtotal) }}</span></td>
            </tr>

            @if($discount > 0)
            <tr class="discount">
                <td class="label">{{ __('Discount') }}</td>
                <td class="val">− <span class="bidi">{{ $order->currency ?? 'USD' }} {{ $fmt($discount) }}</span></td>
            </tr>
            @endif

            <tr class="shipping">
                <td class="label">{{ __('Shipping') }}</td>
                <td class="val"><span class="bidi">{{ $order->currency ?? 'USD' }} {{ $fmt($shipping) }}</span></td>
            </tr>

            @if($feesPct > 0)
            <tr class="fees">
                <td class="label">{{ __('Service Fees (:pct%)', ['pct' => $fmtPct($feesPct)]) }}</td>
                <td class="val"><span class="bidi">{{ $order->currency ?? 'USD' }} {{ $fmt($feesVal) }}</span></td>
            </tr>
            @endif

            <tr class="grand">
                <td class="label">{{ __('Total') }}</td>
                <td class="val"><span class="bidi">{{ $order->currency ?? 'USD' }} {{ $fmt($grandTotal) }}</span></td>
            </tr>

            @if($paid > 0)
            <tr>
                <td class="label">{{ __('Paid') }}</td>
                <td class="val"><span class="bidi">{{ $order->currency ?? 'USD' }} {{ $fmt($paid) }}</span></td>
            </tr>
            <tr class="due">
                <td class="label">{{ __('Due') }}</td>
                <td class="val"><span class="bidi">{{ $order->currency ?? 'USD' }} {{ $fmt($due) }}</span></td>
            </tr>
            @endif
        </table>
    </div>

    {{-- === Persistent footer content (company info) — only for AE === --}}
    @if($showCompanyBlock)
    <div class="footer">
        <div class="left">
            <strong>{{ $companyName }}</strong> — {{ $companyAddr1 }}, {{ $companyAddr2 }}
        </div>
        <div class="right">
            {{ $companyWeb }} • {{ $companyEmail }} • {{ $companyPhone }}
        </div>
    </div>
    @endif

    {{-- === Page numbers (Dompdf canvas) === --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            $size = 9;

            // Centered "Page X of Y"
            $text = "{{ __('Page :n of :c') }}";
            $text = str_replace([':n', ':c'], ['{PAGE_NUM}', '{PAGE_COUNT}'], $text);

            $w = $fontMetrics->get_text_width($text, $font, $size);
            $x = 297 - ($w / 2); // ~A4 width 595pt / 2 ≈ 297

            // Put numbers a bit higher if there's no footer bar
            $y = {{ $showCompanyBlock ? 825 : 812 }};

            // Left footer label: document code
            $leftText = "{{ $docCode }}";
            $pdf->page_text(28, $y, $leftText, $font, $size, [0.45,0.45,0.45]);

            // Right footer label: date
            $rightText = "{{ $docDate }}";
            $pdf->page_text(530, $y, $rightText, $font, $size, [0.45,0.45,0.45]);

            // Centered page counter
            $pdf->page_text($x, $y, $text, $font, $size, [0.35,0.35,0.35]);
        }
    </script>
</body>
</html>
