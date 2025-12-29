<?php

return [
    // Sections
    'type_parties'    => 'Type & Parties',
    'items'           => 'Items',
    'items_desc'      => 'Search by SKU or Name. Sale price used if available; stock based on selected branch.',
    'currency_totals' => 'Currency & Totals',

    // Fields (Left)
    'type'           => 'Type',
    'proforma'       => 'Proforma',
    'order'          => 'Order',
    'branch'         => 'Branch',
    'seller'         => 'Seller',
    'customer'       => 'Customer',
    'customer_hint'  => 'Required for Order, optional for Proforma.',
    'customer_name'  => 'Customer Name',

    // Order Status (When Type = Order)
    'status'            => 'Status',
    'status_draft'      => 'Draft',
    'status_pending'    => 'Pending',
    'status_processing' => 'Processing',
    'status_completed'  => 'Completed',
    'status_cancelled'  => 'Cancelled',
    'status_refunded'   => 'Refunded',
    'status_failed'     => 'Failed',
    'status_on_hold'    => 'On Hold',

    'payment_status' => 'Payment Status',
    'unpaid'         => 'Unpaid',
    'paid'           => 'Paid',
    'partial'        => 'Partial',
    'paid_amount'    => 'Paid Amount',
    'paid_amount_hint' => 'Enter the amount received for this order.',

    // Items Repeater
    'product'      => 'Product',
    'qty'          => 'Qty',
    'unit'         => 'Unit Price',
    'line_total'   => 'Subtotal',
    'stock'        => 'Stock',
    'prev_sales'   => 'Previous Sales',
    'on'           => 'on',
    'no_extra'     => 'No extra information.',
    'item_exists'  => 'This item is already in the list.',
    'add_item'     => 'Add Item',
    'item_note'    => 'Item Note',
    'item_note_placeholder' => 'Internal/Customer note for this item (appears on PDF).',
    'validation_empty_item' => 'Please fill the empty item before adding a new one.',

    // Totals Panel (Right)
    'currency'       => 'Currency',
    'usd_to_rate'    => 'USD → Conversion Rate',
    'subtotal'       => 'Subtotal',
    'discount'       => 'Discount',
    'shipping'       => 'Shipping',
    'extra_fees'     => 'Extra Fees %',
    'extra_fees_hint'=> 'Percentage applied to subtotal',
    'total'          => 'Total',
    'invoice_note'   => 'Invoice Note (appears at bottom of PDF)',
    'invoice_note_placeholder' => 'Any message or terms to print at the bottom of the invoice.',
];