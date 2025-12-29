@php
    // --- Math & Formatting Helpers ---
    $mul = fn($a,$b,$s=4) => bcmul((string)$a,(string)$b,$s);
    $add = fn($a,$b,$s=4) => bcadd((string)$a,(string)$b,$s);
    $sub = fn($a,$b,$s=4) => bcsub((string)$a,(string)$b,$s);
    $fmt = fn($v) => number_format((float)$v, 2, '.', ',');

    $locale = app()->getLocale();
    $isRtl  = ($locale === 'ar');
    $dir    = $isRtl ? 'rtl' : 'ltr';

    // --- Determine Document Type (Order vs Proforma) ---
    // Check the 'type' column from your database screenshot
    $orderType = $order->type ?? 'order'; 
    $isProforma = ($orderType === 'proforma');

    // --- Dynamic Labels based on Type ---
    if ($isRtl) {
        $labelNumber = $isProforma ? 'رقم عرض السعر' : 'رقم الطلب';
        $labelDate   = $isProforma ? 'تاريخ عرض السعر' : 'تاريخ الطلب';
    } else {
        // "Quota" usually implies a limit; "Quote" is the standard term for a proforma/price estimate.
        $labelNumber = $isProforma ? 'Quote Number' : 'Order Number';
        $labelDate   = $isProforma ? 'Quote Date' : 'Order Date';
    }

    // --- Simple translations (EN / AR) ---
    if ($isRtl) {
        $t = [
            'customer_details' => 'تفاصيل العميل',
            'name'             => 'الاسم',
            'phone'            => 'الهاتف',
            'email'            => 'البريد الإلكتروني',
            'country'          => 'الدولة',
            'address'          => 'العنوان',
            'sku'              => 'الرمز',
            'image'            => 'الصورة',
            'product'          => 'المنتج',
            'price'            => 'السعر',
            'qty'              => 'الكمية',
            'total'            => 'الإجمالي',
            'customer_id'      => 'رقم العميل',
            'order_summary'    => 'ملخص الطلب',
            'subtotal'         => 'الإجمالي الفرعي',
            'shipping'         => 'الشحن',
            'discount'         => 'الخصم',
            'service_fees'     => 'رسوم الخدمة',
            'note'             => 'ملاحظة',
            'paid'             => 'مدفوع',
            'unpaid'           => 'غير مدفوع',
        ];
    } else {
        $t = [
            'customer_details' => 'Customer Details',
            'name'             => 'Name',
            'phone'            => 'Phone',
            'email'            => 'Email',
            'country'          => 'Country',
            'address'          => 'Address',
            'sku'              => 'SKU',
            'image'            => 'Image',
            'product'          => 'Product',
            'price'            => 'Price',
            'qty'              => 'Quantity',
            'total'            => 'Total',
            'customer_id'      => 'Customer ID',
            'order_summary'    => 'Order Summary',
            'subtotal'         => 'Sub total',
            'shipping'         => 'Shipping',
            'discount'         => 'Discount',
            'service_fees'     => 'Service Fees',
            'note'             => 'Note',
            'paid'             => 'PAID',
            'unpaid'           => 'UNPAID',
        ];
    }

    // --- Configuration ---
    $companyName  = config('app.name', 'Techno Lock Keys');
    $companyAddress = [
        'Industrial Area 5, Maleha Street',
        'United Arab Emirates – Sharjah',
        '(+971) 50 442 9045',
        'info@tlkeys.com',
    ];

    $docCode    = $order->code ?? 'ORD-'.$order->id;
    $docDate    = $order->created_at?->format('d-m-Y') ?? date('d-m-Y');
    $customerId = $order->customer_id
        ? 'TLKC'.str_pad($order->customer_id, 7, '0', STR_PAD_LEFT)
        : '-';
    $cur        = $order->currency ?: 'USD';

    // Branch code (used to decide whether to show TLKeys header/logo)
    $branchCode = $order->branch->code ?? $order->branch_code ?? null;

    // --- Customer Data ---
    $cust   = $order->customer;
    $cName  = $cust->name ?? $order->customer_name_manual ?? 'Guest';

    $addrParts = [];
    if (!empty($cust->address)) $addrParts[] = $cust->address;
    if (!empty($cust->city))    $addrParts[] = $cust->city;
    if (!empty($cust->country)) $addrParts[] = $cust->country;
    $cAddress = implode(', ', $addrParts);

    // --- Totals Calculation ---
    $linesTotal = '0';
    foreach ($order->items as $row) {
        $total = $mul((string)$row->qty, (string)$row->unit_price, 4);
        $linesTotal = $add($linesTotal, $total, 4);
        $row->__line_total = $total;
    }

    $subtotal = $linesTotal;
    $discount = (string)($order->discount ?? 0);
    $shipping = (string)($order->shipping ?? 0);
    $feesPct  = (float)($order->extra_fees_percent ?? 0);

    $feesVal = '0';
    if ($feesPct > 0) {
        $base    = $add($sub($subtotal, $discount, 4), $shipping, 4);
        $feesVal = $mul($base, (string)($feesPct / 100), 4);
    }

    $grandTotal = $add($add($sub($subtotal, $discount, 4), $shipping, 4), $feesVal, 4);
    $paidAmount = (string)($order->paid_amount ?? 0);

    // --- Status Stamp ---
    $isPaid      = $order->payment_status === 'paid';
    $stampText   = $isPaid ? $t['paid'] : $t['unpaid'];
    $stampColor  = $isPaid ? '#22c55e' : '#ef4444';
    $borderColor = $isPaid ? '#22c55e' : '#ef4444';

    $shippingLabel = ($isRtl ? $t['shipping'].' ' : $t['shipping'].' ')
        .strtoupper($order->shipping_method ?? '');
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $docCode }}</title>

    {{-- Bootstrap CDN (optional for DOMPDF) --}}
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    >

    <style>
        @page {
            margin-top: 1cm;
            margin-bottom: 2cm;
            margin-left: 0.8cm;
            margin-right: 0.8cm;
            footer: page-footer;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #333333;
            line-height: 1.4;
        }

        table { width: 100%; border-collapse: collapse; border-spacing: 0; }
        td, th { vertical-align: top; }

        .nowrap { white-space: nowrap; }

        /* Header */
        .header-table { margin-bottom: 10px; }
        .company-name {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .company-line {
            font-size: 9pt;
            color: #555555;
        }
        .logo-img {
            width: 140px;
            height: auto !important;
            display: block;
            object-fit: contain;
        }
        .logo-img-ltr {
            margin-left: auto;
            margin-right: 0;
        }
        .logo-img-rtl {
            margin-right: auto;
            margin-left: 0;
        }
        .order-meta {
            font-size: 8.5pt;
            color: #444444;
            margin-top: 6px;
            text-align: right;
        }
        .order-meta .label { font-weight: bold; }

        /* Section titles */
        .section-title {
            font-size: 10pt;
            font-weight: bold;
            color: #333333;
            margin: 18px 0 6px;
        }
        .section-title-line {
            border-bottom: 1px solid #cccccc;
            margin-bottom: 8px;
        }

        /* Customer details */
        .cust-label { font-weight: bold; color: #333333; }
        .cust-value { color: #444444; }

        /* Items table (full bordered) */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            border: 1px solid #cfcfcf;
        }
        .items-table thead th {
            background-color: #343A40;
            color: #ffffff;
            font-size: 9pt;
            padding: 8px 6px;
            border: 1px solid #343A40;
        }
        .items-table tbody td {
            font-size: 9pt;
            padding: 8px 6px;
            border-left: 1px solid #c1c1c1;
            border-right: 1px solid #c1c1c1;
            border-bottom: 2px solid #c1c1c1 !important;
            border-top: 2px solid #c1c1c1 !important;
        }
        .items-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .text-center { text-align: center; }
        .text-right  { text-align: right; }

        /* Bigger product image */
        .thumb {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border: 1px solid #dddddd;
            border-radius: 3px;
            margin: 0 auto;
            background-color: #ffffff;
        }

        /* Smaller product name font + fixed height */
        .product-name {
            font-weight: bold;
            color: #333333;
            font-size: 8.5pt;
            line-height: 1.3;
            display: block;
            min-height: 28px;
        }

        .sku-badge {
            display: inline-block;
            font-family: monospace;
            background: #f3f3f3;
            border: 1px solid #e0e0e0;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 8pt;
            color: #444444;
            margin-right: 4px;
        }
        .item-note {
            color: #198754;
            font-size: 8pt;
        }

        /* Totals */
        .totals-wrapper { margin-top: 10px; }

        .totals-table {
            border-collapse: collapse;
            border: 1px solid #cfcfcf;
            font-size: 9pt;
            background: #ffffff;
            width: 260px;
        }
        .totals-table thead th {
            background-color: #343A40;
            color: #ffffff;
            font-size: 9pt;
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid #343A40;
        }
        .totals-table tbody td {
            padding: 6px 8px;
            border-top: 1px solid #e2e2e2;
        }
        .totals-label { text-align: left; }
        .totals-value { text-align: right; }
        .totals-row-gray td { background-color: #f7f7f7; }
        .totals-row-total td {
            background-color: #f1f1f1;
            font-weight: bold;
        }
        .totals-row-total .totals-value { color: #dc2626; }
        .totals-accent {
            color: #dc2626;
            font-weight: bold;
        }

        /* Stamp (under order summary, centered; width per language) */
        .stamp {
            display: block;
            border: 3px solid {{ $borderColor }};
            color: {{ $stampColor }};
            font-size: 15pt;
            font-weight: bold;
            text-transform: uppercase;
            border-radius: 4px;
            white-space: nowrap;
            margin-top: 8px;
            padding: 6px 14px;
            text-align: center;
            width:120px;
        }
        /* Note section */
        .note-label {
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 4px;
        }
        .note-line {
            border-top: 1px dotted #cccccc;
            margin-bottom: 4px;
        }
        .note-content {
            font-size: 9pt;
            color: #555555;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    {{-- Header --}}
    <table class="header-table">
        <tr>
            <td width="60%">
                @if($branchCode === 'AE')
                    <div class="company-name">Techno Lock Keys</div>
                    @foreach($companyAddress as $line)
                        <div class="company-line">{{ $line }}</div>
                    @endforeach
                @endif
            </td>
            <td width="40%" style="text-align:right;">
                @if($branchCode === 'AE' && !empty($logoPath))
                    {{-- Logo on the right for EN, on the LEFT for AR --}}
                    <img
                        class="logo-img {{ $isRtl ? 'logo-img-rtl' : 'logo-img-ltr' }}"
                        src="https://dev-srv.tlkeys.com/storage/AAAA/techno-lock-desktop-logo.jpg"
                        alt="Logo"
                    >
                @endif

                <div class="order-meta">
                    {{-- Dynamic Doc Number Label --}}
                    <div>
                        <span class="label">{{ $labelNumber }}: </span>
                        <span>#{{ $docCode }}</span>
                    </div>
                    {{-- Dynamic Doc Date Label --}}
                    <div>
                        <span class="label">{{ $labelDate }}: </span>
                        <span>{{ $docDate }}</span>
                    </div>
                    <div>
                        <span class="label">{{ $t['customer_id'] }}: </span>
                        <span>{{ $customerId }}</span>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Customer Details --}}
    <div class="section-title">{{ $t['customer_details'] }}</div>
    <div class="section-title-line"></div>

    <table>
        <tr>
            <td width="15%" class="cust-label">{{ $t['name'] }}:</td>
            <td width="85%" class="cust-value">{{ $cName }}</td>
        </tr>
        @if($cAddress)
            <tr>
                <td class="cust-label">{{ $t['address'] }}:</td>
                <td class="cust-value">{{ $cAddress }}</td>
            </tr>
        @endif
        @if(!empty($cust->country))
            <tr>
                <td class="cust-label">{{ $t['country'] }}:</td>
                <td class="cust-value">{{ $cust->country }}</td>
            </tr>
        @endif
        @if(!empty($cust->phone))
            <tr>
                <td class="cust-label">{{ $t['phone'] }}:</td>
                <td class="cust-value">{{ $cust->phone }}</td>
            </tr>
        @endif
        @if(!empty($cust->email))
            <tr>
                <td class="cust-label">{{ $t['email'] }}:</td>
                <td class="cust-value">{{ $cust->email }}</td>
            </tr>
        @endif
    </table>

    {{-- Items Table --}}
    <div class="section-title" style="margin-top:10px;">&nbsp;</div>

    <table class="items-table">
        <thead>
            <tr>
                <th width="5%"  class="text-center">#</th>
                <th width="10%">{{ $t['sku'] }}</th>
                <th width="10%" class="text-center">{{ $t['image'] }}</th>
                <th width="35%">{{ $t['product'] }}</th>
                <th width="15%" class="text-center">{{ $t['price'] }}</th>
                <th width="10%" class="text-center">{{ $t['qty'] }}</th>
                <th width="15%" class="text-right">{{ $t['total'] }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($order->items as $idx => $row)
            @php
                $sku = $row->sku;
                if (!$sku && $row->product) {
                    $sku = $row->product->sku;
                }

                $name = $row->product_name;
                if (!$name && $row->product) {
                    $name = $row->product->title;
                    if (is_array($name)) {
                        $name = $name[$locale] ?? $name['en'] ?? reset($name);
                    }
                }

                $img = $imgMap[$row->product_id] ?? null;
            @endphp
            <tr>
                <td class="text-center" style="vertical-align: middle">{{ $idx + 1 }}</td>
                <td style="vertical-align: middle">{{ $sku }}</td>
                <td class="text-center" style="vertical-align: middle">
                    @if($img)
                        <img src="{{ $img }}" class="thumb" alt="">
                    @endif
                </td>
                <td>
                    <span class="product-name">{{ $name }}</span>
                    @if($row->note)
                        <div class="item-note">{{ $row->note }}</div>
                    @endif
                </td>
                <td class="text-center" style="vertical-align: middle">
                    <span class="nowrap">{{ $fmt($row->unit_price) }} {{ $cur }}</span>
                </td>
                <td class="text-center" style="vertical-align: middle">{{ (float)$row->qty }}</td>
                <td class="text-right" style="vertical-align: middle">
                    <span class="nowrap">{{ $fmt($row->__line_total) }} {{ $cur }}</span>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{-- Totals + centered stamp under the table --}}
    <table class="totals-wrapper">
        <tr>
            <td style="width:60%;"></td>
            <td style="width:40%; text-align:right;">
                <table class="totals-table">
                    <thead>
                        <tr>
                            <th colspan="2">{{ $t['order_summary'] }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="totals-row-gray">
                            <td class="totals-label">{{ $t['subtotal'] }}</td>
                            <td class="totals-value">
                                <span class="nowrap">{{ $fmt($subtotal) }} {{ $cur }}</span>
                            </td>
                        </tr>

                        <tr>
                            <td class="totals-label">
                                {{ trim($shippingLabel) ?: $t['shipping'] }}
                            </td>
                            <td class="totals-value">
                                <span class="nowrap">{{ $fmt($shipping) }} {{ $cur }}</span>
                            </td>
                        </tr>

                        @if((float)$discount > 0)
                            <tr>
                                <td class="totals-label">{{ $t['discount'] }}</td>
                                <td class="totals-value">
                                    <span class="nowrap">-{{ $fmt($discount) }} {{ $cur }}</span>
                                </td>
                            </tr>
                        @endif

                        @if($feesPct > 0)
                            <tr>
                                <td class="totals-label">{{ $t['service_fees'] }}</td>
                                <td class="totals-value">
                                    <span class="nowrap">
                                        <span class="totals-accent">{{ $feesPct }}%</span>
                                        ({{ $fmt($feesVal) }} {{ $cur }})
                                    </span>
                                </td>
                            </tr>
                        @endif

                        <tr class="totals-row-total">
                            <td class="totals-label">{{ $t['total'] }}</td>
                            <td class="totals-value">
                                <span class="nowrap">{{ $fmt($grandTotal) }} {{ $cur }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                {{-- centered stamp just under the totals table --}}
                <div class="stamp" style="margin-left:auto;">{{ $stampText }}</div>
            </td>
        </tr>
    </table>
    
    {{-- Note --}}
    <div class="note-label">{{ $t['note'] }}:</div>
    <div class="note-line"></div>
    @if($order->invoice_note)
        <div class="note-content">{!! nl2br(e($order->invoice_note)) !!}</div>
    @endif
</body>
</html>