<?php

namespace App\Notifications;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $productId,
        public int $branchId,
        public int $stock,
        public int $alert
    ) {
        //
    }

    public function via(object $notifiable): array
    {
        // Filament shows database notifications in the bell by default
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $product = Product::find($this->productId);
        $branch  = Branch::find($this->branchId);

        return [
            'title'      => 'Low stock alert',
            'message'    => sprintf(
                '%s (%s) @ %s stock=%d (alert=%d)',
                $product?->title ?? 'Product',
                $product?->sku ?? 'SKU',
                $branch?->code ?? 'BR',
                $this->stock,
                $this->alert
            ),
            'product_id' => $this->productId,
            'branch_id'  => $this->branchId,
            'stock'      => $this->stock,
            'alert'      => $this->alert,
        ];
    }
}
