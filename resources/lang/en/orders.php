<?php

return [
    // Sections
    'type_parties'   => 'Type & Parties',
    'items'          => 'Items',
    'items_desc'     => 'Search by SKU or title. Sale price is used when available; stock is per selected branch.',
    'currency_totals'=> 'Currency & Totals',

    // Fields (left)
    'type'           => 'Type',
    'proforma'       => 'Proforma',
    'order'          => 'Order',
    'branch'         => 'Branch',
    'seller'         => 'Seller',
    'customer'       => 'Customer',
    'customer_hint'  => 'Required for Order, optional for Proforma.',
    'customer_name'  => 'Customer name',

    // Order state fields (only when type = order)
    'status'             => 'Status',
    'status_draft'       => 'Draft',
    'status_pending'     => 'Pending',
    'status_processing'  => 'Processing',
    'status_completed'   => 'Completed',
    'status_cancelled'   => 'Cancelled',
    'status_refunded'    => 'Refunded',
    'status_failed'      => 'Failed',
    'status_on_hold'     => 'On hold',

    'payment_status' => 'Payment status',
    'unpaid'         => 'Unpaid',
    'paid'           => 'Paid',

    // Items repeater
    'product'      => 'Product',
    'qty'          => 'Qty',
    'unit'         => 'Unit price',
    'line_total'   => 'Line total',
    'stock'        => 'Stock',
    'prev_sales'   => 'Previous sales',
    'on'           => 'on',
    'no_extra'     => 'No extra info available.',
    'item_exists'  => 'This product is already in the list.',

    // Right panel (totals)
    'currency'     => 'Currency',
    'usd_to_rate'  => 'USD → Currency rate',
    'subtotal'     => 'Subtotal',
    'discount'     => 'Discount',
    'shipping'     => 'Shipping',
    'extra_fees'   => 'Extra fees %',
    'extra_fees_hint' => 'Percentage applied on subtotal',
    'total'        => 'Total',
        'partial' => 'Partial',
    'paid_amount' => 'Paid amount',
    'paid_amount_hint' => 'Enter the amount received for this order.',
    'add_item' => 'Add item',
    'item_note' => 'Item note',
    'item_note_placeholder' => 'Internal/customer note for this item (shown on PDF).',
    'invoice_note' => 'Invoice note (appears at bottom of PDF)',
    'invoice_note_placeholder' => 'Any message or terms to print at the bottom of the invoice.',

];
