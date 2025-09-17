<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Facades\Notification;

class InventoryService
{
    /**
     * Adjust stock for a product in a specific branch by a delta.
     * Positive delta => add stock, Negative delta => subtract stock.
     */
    public static function adjust(Product $product, int $branchId, int $deltaQty): void
    {
        // ensure pivot exists
        $pivot = $product->branches()->where('branches.id', $branchId)->first()?->pivot;

        if (!$pivot) {
            // If product isn't linked to branch yet, attach with zeroes first
            $product->branches()->attach($branchId, ['stock' => 0, 'stock_alert' => 0]);
            $pivot = $product->branches()->where('branches.id', $branchId)->first()->pivot;
        }

        $newStock = max(0, ((int) $pivot->stock) + $deltaQty);
        $product->branches()->updateExistingPivot($branchId, ['stock' => $newStock]);

        // Check alert threshold
        $alert = (int) $pivot->stock_alert;
        if ($alert > 0 && $newStock <= $alert) {
            self::notifyLowStock($product, $branchId, $newStock, $alert);
        }
    }

    /**
     * Set stock & alert explicitly (used by imports).
     * This will also trigger notification if <= alert.
     */
    public static function set(Product $product, int $branchId, int $stock, int $stockAlert = null): void
    {
        // ensure pivot row
        $product->branches()->syncWithoutDetaching([
            $branchId => [
                'stock'       => $stock,
                'stock_alert' => $stockAlert ?? (int) optional(
                    $product->branches()->where('branches.id', $branchId)->first()
                )->pivot?->stock_alert ?? 0,
            ],
        ]);

        $pivot = $product->branches()->where('branches.id', $branchId)->first()->pivot;
        $currentAlert = (int) $pivot->stock_alert;

        if ($currentAlert > 0 && $stock <= $currentAlert) {
            self::notifyLowStock($product, $branchId, $stock, $currentAlert);
        }
    }

    protected static function notifyLowStock(Product $product, int $branchId, int $stock, int $alert): void
    {
        $admins = User::role('admin')->get(); // requires spatie/permission
        Notification::send($admins, new LowStockNotification($product->id, $branchId, $stock, $alert));
    }
}
