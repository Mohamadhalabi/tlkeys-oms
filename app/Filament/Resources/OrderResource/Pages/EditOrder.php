<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\CurrencyRate;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // keep copy of original items for afterSave stock adjustment
        $this->data['__original_items__'] = $this->record->items()
            ->get(['product_id','qty'])
            ->map(fn ($i) => ['product_id' => (int) $i->product_id, 'qty' => (int) $i->qty])
            ->toArray();

        $code = $data['currency'] ?? 'USD';
        $rate = CurrencyRate::getRate($code);
        $data['exchange_rate'] = $rate;

        // Subtotal in USD from POSTed items
        $subtotalUsd = collect($data['items'] ?? [])
            ->sum(fn ($i) => (float)($i['qty'] ?? 0) * (float)($i['unit_price'] ?? 0));

        // Already USD (converted in the form)
        $discountUsd = (float)($data['discount'] ?? 0);
        $shippingUsd = (float)($data['shipping'] ?? 0);

        $data['subtotal'] = round($subtotalUsd, 2);
        $data['discount'] = round($discountUsd, 2);
        $data['shipping'] = round($shippingUsd, 2);
        $data['total']    = round(max(0, $subtotalUsd - $discountUsd + $shippingUsd), 2);

        return $data;
    }

    protected function afterSave(): void
    {
        // Make sure we have items loaded off the saved record
        $order    = $this->record->loadMissing('items');
        $branchId = (int) $order->branch_id;

        // 1) Adjust stock by diff (new - old)
        $old = collect($this->data['__original_items__'] ?? [])
            ->groupBy('product_id')->map(fn ($g) => (int) $g->sum('qty'));

        $new = $order->items
            ->groupBy('product_id')->map(fn ($g) => (int) $g->sum('qty'));

        $allProductIds = $old->keys()->merge($new->keys())->unique();

        foreach ($allProductIds as $pid) {
            $before   = (int) ($old[$pid] ?? 0);
            $after    = (int) ($new[$pid] ?? 0);
            $deltaQty = $after - $before; // positive if order qty increased

            if ($deltaQty !== 0) {
                // stock moves opposite to order qty change
                self::adjustBranchStock((int) $pid, $branchId, -1 * $deltaQty);
            }
        }

        // 2) Recompute & persist financials from DB items (always in USD)
        $subtotalUsd = (float) $order->items->sum(fn ($i) => (float) $i->qty * (float) $i->unit_price);
        $discountUsd = (float) ($order->discount ?? 0);
        $shippingUsd = (float) ($order->shipping ?? 0);

        $order->subtotal = round($subtotalUsd, 2);
        $order->total    = round(max(0, $subtotalUsd - $discountUsd + $shippingUsd), 2);

        // Ensure exchange rate exists for the selected currency
        if (! $order->exchange_rate) {
            $order->exchange_rate = \App\Models\CurrencyRate::getRate($order->currency ?: 'USD');
        }

        $order->saveQuietly();
    }


    public static function adjustBranchStock(int $productId, int $branchId, int $delta): void
    {
        if ($branchId <= 0) return;

        DB::table('product_branch')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->update([
                'stock' => DB::raw('GREATEST(0, stock + (' . $delta . '))'),
            ]);
    }
}
