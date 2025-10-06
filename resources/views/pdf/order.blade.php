@php
    $isRtl   = in_array(app()->getLocale(), ['ar','ar_SA','ar_EG','ar-LB']);
    $cur     = $order->currency ?: 'USD';
    $rate    = (float) ($order->exchange_rate ?? 1);
    $orderNo = $order->uuid ?? ('TLO' . str_pad($order->id, 6, '0', STR_PAD_LEFT));
    $custNo  = $order->customer?->uuid ?? null;

    /**
     * Resolve many kinds of "relative" paths to a local absolute path and prefix file://
     * Accepts:
     *   products/abc.jpg
     *   storage/products/abc.jpg
     *   /storage/products/abc.jpg
     *   /img/abc.jpg (anything under public/)
     */
    $toLocalImg = function (?string $path) {
        if (!$path) return null;
        $path = trim($path);

        // Remote URLs? Don't fetch (avoid timeouts)
        if (preg_match('~^https?://~i', $path)) return null;

        // normalize leading slash
        $trim = ltrim($path, '/');

        // 1) storage disk ("public") e.g. products/abc.jpg
        try {
            $diskAbs = \Illuminate\Support\Facades\Storage::disk('public')->path($trim);
            if (is_file($diskAbs)) return 'file://' . $diskAbs;
        } catch (\Throwable $e) {}

        // 2) /storage/... symlink (public/storage/...)
        $maybe = public_path($trim);
        if (is_file($maybe)) return 'file://' . $maybe;

        if (str_starts_with($trim, 'storage/')) {
            $maybe2 = public_path($trim); // public/storage/...
            if (is_file($maybe2)) return 'file://' . $maybe2;
        }

        // 3) raw file under public
        $pub = public_path($trim);
        if (is_file($pub)) return 'file://' . $pub;

        return null; // not found
    };

    $logoLocal = isset($logoPath) && is_file($logoPath) ? ('file://' . $logoPath) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $orderNo }}</title>
    <style>
        @page { margin: 16mm 14mm 20mm 14mm; }
        body  { font-family: 'amiri', sans-serif; color:#111; font-size: 10.8pt; }

        .brand { font-size: 18pt; font-weight: 700; margin: 0 0 2mm; }
        .muted { color:#6b7280; font-size: 9.5pt; }

        /* Header row */
        .hdr { width:100%; border-collapse: collapse; margin-bottom: 8mm; }
        .hdr td { vertical-align: middle; }
        .hdr .logo { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .hdr .logo img { height: 44px; }

        /* Card using table for reliability */
        .card { width:100%; border:1px solid #e5e7eb; border-radius:8px; border-collapse: separate; border-spacing:0; }
        .card td { padding:8px 10px; vertical-align: top; }
        .card td + td { border-{{ $isRtl ? 'right' : 'left' }}:1px solid #e5e7eb; }
        .title-sm { font-weight:700; margin-bottom:4px; }

        /* Items table */
        table.items { width:100%; border-collapse: collapse; margin-top: 10mm; }
        table.items th, table.items td { border:1px solid #e5e7eb; padding: 7px 8px; vertical-align: middle; }
        table.items thead th { background:#374151; color:#fff; font-weight:700; font-size:10pt; }
        table.items tbody tr:nth-child(odd) { background:#fafafa; }
        .center { text-align:center; }
        .right  { text-align: {{ $isRtl ? 'left' : 'right' }}; }
        .sku { color:#6b7280; font-size: 9.5pt; }
        .prod-title { font-weight:700; }

        /* Product image (small thumbnail) */
        .pimg { width: 44px; height:44px; display:inline-block; border:1px solid #e5e7eb; border-radius:6px; background:#fff; }
        .pimg img { max-width:44px; max-height:44px; width:auto; height:auto; object-fit:contain; display:block; }

        /* Totals */
        .totals { width: 40%; margin-top:10mm; margin-{{ $isRtl ? 'right':'left' }}:auto; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
        .totals td { border:0; padding:8px; }
        .totals tr + tr td { border-top:1px solid #f1f5f9; }
        .totals .label { color:#374151; }
        .totals .value { font-weight:600; }

        /* Footer page numbers */
        htmlpagefooter[name=fo] { text-align:center; color:#6b7280; font-size:10pt; }
    </style>
</head>
<body>

<htmlpagefooter name="fo">
    {{ __('Page') }} {PAGENO} {{ __('of') }} {nb}
</htmlpagefooter>
<sethtmlpagefooter name="fo" value="on" />

<table class="hdr">
    <tr>
        <td>
            <div class="brand">{{ $company ?? 'Your Company' }}</div>
            <div class="muted">
                <strong>{{ __('Order Number') }}:</strong> {{ $orderNo }}
                @if($custNo) &nbsp;|&nbsp; <strong>{{ __('Customer #') }}:</strong> {{ $custNo }} @endif
                &nbsp;|&nbsp; <strong>{{ __('Date') }}:</strong> {{ $order->created_at?->format('Y-m-d') }}
            </div>
        </td>
        <td class="logo">
            @if($logoLocal)<img src="{{ $logoLocal }}" alt="Logo">@endif
        </td>
    </tr>
</table>

<table class="card">
    <tr>
        <td style="width:50%;">
            <div class="title-sm">{{ __('Customer Details') }}</div>
            <div>{{ $order->customer?->name }}</div>
            @if($order->customer?->address)<div>{{ $order->customer->address }}</div>@endif
            @if($order->customer?->phone)<div>{{ $order->customer->phone }}</div>@endif
            @if($order->customer?->email)<div>{{ $order->customer->email }}</div>@endif
        </td>
        <td style="width:50%;">
            <div class="title-sm">{{ __('Seller / Order Info') }}</div>
            <div><strong>{{ __('Seller') }}:</strong> {{ $order->seller?->name }}</div>
            <div><strong>{{ __('Branch') }}:</strong> {{ $order->branch?->name ?? $order->branch?->code }}</div>
            <div><strong>{{ __('Currency') }}:</strong> {{ $cur }} &nbsp;|&nbsp; <strong>{{ __('Rate') }}:</strong> {{ number_format($rate, 6) }}</div>
            <div><strong>{{ __('Type') }}:</strong> {{ ucfirst($order->type) }}</div>
            <div><strong>{{ __('Status') }}:</strong> {{ str_replace('_',' ', ucfirst($order->status)) }}</div>
        </td>
    </tr>
</table>

{{-- …header + info card as in my previous reply… --}}

<table class="items">
    <thead>
    <tr>
        <th class="center" style="width:28px;">#</th>
        <th class="center" style="width:56px;">{{ __('Image') }}</th>
        <th style="width:110px;">SKU</th>
        <th>{{ __('Product') }}</th>
        <th class="center" style="width:70px;">{{ __('Qty') }}</th>
        <th class="right"  style="width:95px;">{{ __('Unit Price') }} ({{ $order->currency ?? 'USD' }})</th>
        <th class="right"  style="width:110px;">{{ __('Line Total') }} ({{ $order->currency ?? 'USD' }})</th>
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
            <td class="center">{{ $i + 1 }}</td>
            <td class="center">
                <span class="pimg">
                    @if($src)<img src="{{ $src }}" alt="prod">@endif
                </span>
            </td>
            <td><span class="sku">{{ $item->product?->sku }}</span></td>
            <td><div class="prod-title">{{ $title }}</div></td>
            <td class="center">{{ rtrim(rtrim(number_format((float)$item->qty, 2, '.', ''), '0'), '.') }}</td>
            <td class="right">{{ number_format((float)$item->unit_price * (float)($order->exchange_rate ?? 1), 2) }}</td>
            <td class="right">{{ number_format((float)$item->line_total * (float)($order->exchange_rate ?? 1), 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="totals">
    <table>
        <tr>
            <td class="label right">{{ __('Sub total') }} ({{ $cur }})</td>
            <td class="value right">{{ number_format((float)$order->subtotal * $rate, 2) }}</td>
        </tr>
        <tr>
            <td class="label right">{{ __('Discount') }} ({{ $cur }})</td>
            <td class="value right">{{ number_format((float)$order->discount * $rate, 2) }}</td>
        </tr>
        <tr>
            <td class="label right">{{ __('Shipping') }} ({{ $cur }})</td>
            <td class="value right">{{ number_format((float)$order->shipping * $rate, 2) }}</td>
        </tr>
        <tr>
            <td class="label right"><strong>{{ __('Total') }} ({{ $cur }})</strong></td>
            <td class="value right"><strong>{{ number_format((float)$order->total * $rate, 2) }}</strong></td>
        </tr>
    </table>
</div>

</body>
</html>
