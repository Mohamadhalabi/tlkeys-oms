@php
    $isRtl   = in_array(app()->getLocale(), ['ar','ar_SA','ar_EG','ar-LB']);
    $cur     = $order->currency ?: 'USD';
    $rate    = (float) ($order->exchange_rate ?? 1);
    $orderNo = $order->code ?? ('TLO' . str_pad($order->id, 6, '0', STR_PAD_LEFT));
    $custNo  = $order->customer?->code ?? null;

    $logoLocal = isset($logoPath) && is_file($logoPath) ? ('file://' . $logoPath) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $orderNo }}</title>
    <style>
        /* ===== Bootstrap-ish reset & utils (embedded to avoid CDN) ===== */
        @page { margin: 16mm 14mm 22mm 14mm; }
        html, body { font-family: 'amiri', sans-serif; color:#111; font-size: 10.8pt; }
        .row { display: table; width:100%; table-layout: fixed; }
        .col-6 { display: table-cell; width: 50%; vertical-align: top; }
        .text-right { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .text-center { text-align: center; }
        .small { font-size: 9.5pt; }
        .muted { color:#6b7280; }
        .badge { display:inline-block; padding:2px 6px; border-radius: 10px; font-size: 9pt; background:#e5e7eb; }
        .mb-1{margin-bottom:2mm}.mb-2{margin-bottom:4mm}.mb-3{margin-bottom:6mm}.mb-4{margin-bottom:8mm}
        .mt-2{margin-top:4mm}.mt-3{margin-top:6mm}.mt-4{margin-top:8mm}
        .py-2{padding-top:4mm;padding-bottom:4mm}.px-2{padding-left:4mm;padding-right:4mm}
        .fw-bold{font-weight:700}.fw-semibold{font-weight:600}

        /* Header */
        .brand { font-size: 18pt; font-weight: 700; margin: 0 0 2mm; }
        .hdr       { width:100%; border-collapse: collapse; margin-bottom: 6mm; }
        .hdr td    { vertical-align: middle; }
        .hdr .logo { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .hdr .logo img { height: 46px; }

        /* Card */
        .card {
            width:100%; border:1px solid #e5e7eb; border-radius:8px;
            border-collapse: separate; border-spacing:0;
        }
        .card .card-body td { padding:8px 10px; vertical-align: top; }
        .card .title { font-size:12pt; font-weight:700; margin-bottom:2mm; }
        .kv .k { color:#374151; width: 34%; white-space:nowrap; }
        .kv .v { color:#111; }

        /* Items table styled like Bootstrap table-dark header + stripes */
        table.table { width:100%; border-collapse: collapse; }
        .table th, .table td { border:1px solid #e7eaed; padding:7px 8px; vertical-align: middle; }
        .thead-dark th { background:#343a40; color:#fff; font-weight:700; font-size:10pt; }
        .table-striped tbody tr:nth-child(odd) { background:#fafafa; }

        /* Product image thumbnail */
        .pimg { width:44px; height:44px; display:inline-block; border:1px solid #e5e7eb; border-radius:6px; background:#fff; }
        .pimg img { max-width:44px; max-height:44px; width:auto; height:auto; object-fit:contain; display:block; }

        /* Totals pill on the right */
        .totals {
            width: 46%; margin-{{ $isRtl ? 'right':'left' }}: auto; margin-top: 8mm;
            border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;
        }
        .totals .rowline { display: table; width:100%; }
        .totals .cell { display: table-cell; padding:7px 10px; border-bottom:1px solid #f1f5f9; }
        .totals .cell.label { text-align: {{ $isRtl ? 'left' : 'right' }}; color:#374151; }
        .totals .cell.val   { text-align: {{ $isRtl ? 'right' : 'left' }}; font-weight:600; }
        .totals .rowline:last-child .cell { border-bottom:0; }

        /* Footer with contact + icons */
        htmlpagefooter[name=fo] { font-size:10pt; color:#6b7280; }
        .contact-us { text-align:center; color:#949395; font-size: 10.5pt; }
        .icons img { width:26px; border-radius:10%; margin: 0 8px; }

        /* Page number in footer */
        .pageno { margin-top: 2mm; }
    </style>
</head>
<body>

{{-- Footer content --}}
<htmlpagefooter name="fo">
    <div class="contact-us">
        {{ __('If you have any questions, please email us at') }}
        <span style="color:#D18700">info@tlkeys.com</span> {{ __('or visit our FAQs.') }}<br/>
        {{ __('You can also chat with a real live human during our operating hours.') }}
        <div class="icons" style="margin-top:6px;">
            {{-- Use local icons if you have them in public/storage/icons/* (base64 to avoid external fetch) --}}
            @php
                function icon64($rel) {
                    $p = public_path($rel);
                    return is_file($p) ? 'data:image/'.(pathinfo($p,PATHINFO_EXTENSION)).';base64,'.base64_encode(file_get_contents($p)) : null;
                }
                $fb  = icon64('storage/icons/facebook-icon.png');
                $ig  = icon64('storage/icons/instagram-icon.png');
                $yt  = icon64('storage/icons/youtube-icon.png');
                $wa  = icon64('storage/icons/whatsapp-icon.png');
            @endphp
            @if($fb)<img src="{{ $fb }}" alt="fb">@endif
            @if($ig)<img src="{{ $ig }}" alt="ig">@endif
            @if($yt)<img src="{{ $yt }}" alt="yt">@endif
            @if($wa)<img src="{{ $wa }}" alt="wa">@endif
        </div>
        <div class="pageno">{{ __('Page') }} {PAGENO} {{ __('of') }} {nb}</div>
    </div>
</htmlpagefooter>
<sethtmlpagefooter name="fo" value="on" />

{{-- Header --}}
<table class="hdr">
    <tr>
        <td>
            <div class="brand">{{ $company ?? 'Your Company' }}</div>
            <div class="small">
                <span class="fw-semibold">{{ __('Order Number') }}:</span> {{ $orderNo }}
                @if($custNo) &nbsp;|&nbsp; <span class="fw-semibold">{{ __('Customer ID') }}:</span> {{ $custNo }} @endif
                &nbsp;|&nbsp; <span class="fw-semibold">{{ __('Date') }}:</span> {{ $order->created_at?->format('Y-m-d') }}
            </div>
        </td>
        <td class="logo">
            @if($logoLocal)<img src="{{ $logoLocal }}" alt="logo">@endif
        </td>
    </tr>
</table>

{{-- Customer (left) / Seller (right) card --}}
<table class="card mb-3">
    <tr><td>
        <table class="card-body" style="width:100%; border-collapse:collapse;">
            <tr>
                <td class="col-6" style="border:0;">
                    <div class="title">{{ __('Customer Details') }}</div>
                    <table class="kv" style="width:100%; border-collapse:collapse;">
                        <tr><td class="k">{{ __('Name') }}</td><td class="v">{{ $order->customer?->name }}</td></tr>
                        @if($order->customer?->address)
                            <tr><td class="k">{{ __('Address') }}</td><td class="v">{{ $order->customer->address }}</td></tr>
                        @endif
                        @if($order->customer?->phone)
                            <tr><td class="k">{{ __('Phone') }}</td><td class="v">{{ $order->customer->phone }}</td></tr>
                        @endif
                        @if($order->customer?->email)
                            <tr><td class="k">{{ __('Email') }}</td><td class="v">{{ $order->customer->email }}</td></tr>
                        @endif
                    </table>
                </td>

                <td class="col-6" style="border:0;">
                    <div class="title">{{ __('Seller / Order Info') }}</div>
                    <table class="kv" style="width:100%; border-collapse:collapse;">
                        <tr><td class="k">{{ __('Seller') }}</td><td class="v">{{ $order->seller?->name }}</td></tr>
                        <tr><td class="k">{{ __('Branch') }}</td><td class="v">{{ $order->branch?->name ?? $order->branch?->code }}</td></tr>
                        <tr><td class="k">{{ __('Currency') }}</td><td class="v">{{ $cur }}</td></tr>
                        <tr><td class="k">{{ __('Rate') }}</td><td class="v">{{ number_format($rate, 6) }}</td></tr>
                        <tr><td class="k">{{ __('Type') }}</td><td class="v">{{ ucfirst($order->type) }}</td></tr>
                        <tr><td class="k">{{ __('Status') }}</td><td class="v">{{ str_replace('_',' ', ucfirst($order->status)) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </td></tr>
</table>

{{-- Items --}}
<table class="table table-striped">
    <thead class="thead-dark">
        <tr>
            <th class="text-center" style="width:28px;">#</th>
            <th class="text-center" style="width:56px;">{{ __('Image') }}</th>
            <th class="text-center" style="width:110px;">SKU</th>
            <th>{{ __('Product') }}</th>
            <th class="text-center" style="width:70px;">{{ __('Qty') }}</th>
            <th class="text-right"  style="width:95px;">{{ __('Unit Price') }} ({{ $cur }})</th>
            <th class="text-right"  style="width:110px;">{{ __('Line Total') }} ({{ $cur }})</th>
        </tr>
    </thead>
    <tbody>
    @foreach($order->items as $i => $item)
        @php
            $title = $item->product?->title;
            if (is_array($title)) $title = $title[app()->getLocale()] ?? ($title['en'] ?? reset($title));
            $title = $title ?? $item->product?->sku ?? __('Product');
            $src   = $imgMap[$item->product_id] ?? null;
        @endphp
        <tr>
            <td class="text-center">{{ $i + 1 }}</td>
            <td class="text-center">
                <span class="pimg">@if($src)<img src="{{ $src }}" alt="p">@endif</span>
            </td>
            <td class="text-center"><span class="muted">{{ $item->product?->sku }}</span></td>
            <td class="fw-semibold">{{ $title }}</td>
            <td class="text-center">{{ rtrim(rtrim(number_format((float)$item->qty, 2, '.', ''), '0'), '.') }}</td>
            <td class="text-right">{{ number_format((float)$item->unit_price * $rate, 2) }}</td>
            <td class="text-right">{{ number_format((float)$item->line_total * $rate, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- Totals --}}
<div class="totals">
    <div class="rowline">
        <div class="cell label fw-semibold">{{ __('Sub total') }} ({{ $cur }})</div>
        <div class="cell val">{{ number_format((float)$order->subtotal * $rate, 2) }}</div>
    </div>
    <div class="rowline">
        <div class="cell label fw-semibold">{{ __('Discount') }} ({{ $cur }})</div>
        <div class="cell val">{{ number_format((float)$order->discount * $rate, 2) }}</div>
    </div>
    <div class="rowline">
        <div class="cell label fw-semibold">{{ __('Shipping') }} ({{ $cur }})</div>
        <div class="cell val">{{ number_format((float)$order->shipping * $rate, 2) }}</div>
    </div>
    <div class="rowline">
        <div class="cell label fw-bold"><strong>{{ __('Total') }} ({{ $cur }})</strong></div>
        <div class="cell val fw-bold"><strong>{{ number_format((float)$order->total * $rate, 2) }}</strong></div>
    </div>
</div>

</body>
</html>
