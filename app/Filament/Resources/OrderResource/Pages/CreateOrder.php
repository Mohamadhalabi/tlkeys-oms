<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\CurrencyRate;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // auto-assign seller/branch for sellers
        $user = auth()->user();
        if ($user?->hasRole('seller')) {
            $data['seller_id'] = $user->id;
            $data['branch_id'] = $data['branch_id'] ?? $user->branch_id;
        }

        $code = $data['currency'] ?? 'USD';
        $rate = CurrencyRate::getRate($code);
        $data['exchange_rate'] = $rate;

        $subtotalUsd = collect($data['items'] ?? [])
            ->sum(fn ($i) => (float)($i['qty'] ?? 0) * (float)($i['unit_price'] ?? 0));

        $discountUsd = (float)($data['discount'] ?? 0);
        $shippingUsd = (float)($data['shipping'] ?? 0);

        $data['subtotal'] = round($subtotalUsd, 2);
        $data['discount'] = round($discountUsd, 2);
        $data['shipping'] = round($shippingUsd, 2);
        $data['total']    = round(max(0, $subtotalUsd - $discountUsd + $shippingUsd), 2);

        return $data;
    }

    protected function afterCreate(): void
    {
        $order = $this->record;
        $branchId = (int) $order->branch_id;

        foreach ($order->items as $item) {
            self::adjustBranchStock((int)$item->product_id, $branchId, -1 * (int)$item->qty);
        }
    }

    public static function adjustBranchStock(int $productId, int $branchId, int $delta): void
    {
        if ($branchId <= 0) return;

        DB::table('product_branch')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->update(['stock' => DB::raw('GREATEST(0, stock + (' . $delta . '))')]);
    }
}
