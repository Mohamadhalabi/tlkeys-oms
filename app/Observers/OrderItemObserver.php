<?php

namespace App\Observers;

use App\Models\OrderItem;

class OrderItemObserver
{
    public function saved(OrderItem $item): void
    {
        // Recompute parent order totals any time an item changes
        $item->order?->recalcTotals();
    }

    public function deleted(OrderItem $item): void
    {
        // Guard the relation; recompute parent totals on delete as well
        $item->order?->recalcTotals();
    }
}
