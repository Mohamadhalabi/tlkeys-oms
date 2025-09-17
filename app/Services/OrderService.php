<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Recalculate totals from items.
     */
    public static function recalc(Order $order): void
    {
        $order->loadMissing('items');
        $subtotal = $order->items->sum('line_total');

        $order->subtotal = $subtotal;
        $order->total    = $subtotal - (float) $order->discount;
        $order->save();
    }

    /**
     * Convert a proforma to a real order (no side effects besides type change).
     */
    public static function convertToOrder(Order $order): void
    {
        if ($order->type === 'proforma') {
            $order->type = 'order';
            $order->save();
        }
    }

    /**
     * Confirm an order (debits wallet and decrements stock).
     * Assumes:
     * - $order->type === 'order'
     * - $order->status === 'draft'
     */
    public static function confirm(Order $order): void
    {
        if (!($order->type === 'order' && $order->status === 'draft')) {
            return;
        }

        DB::transaction(function () use ($order) {
            $order->loadMissing(['items.product', 'customer']);

            // Decrement stock per item in the order’s branch
            foreach ($order->items as $item) {
                InventoryService::adjust($item->product, (int) $order->branch_id, -1 * (int) $item->qty);
            }

            // Wallet impact (optional): debit customer's wallet by order total
            if ((float) $order->total > 0) {
                $order->customer->debit((float) $order->total, 'order#' . $order->id, [
                    'order_id' => $order->id,
                ]);
            }

            $order->status = 'confirmed';
            $order->save();
        });
    }
}
