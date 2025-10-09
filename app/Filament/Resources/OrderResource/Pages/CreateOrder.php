<?php
// app/Filament/Resources/OrderResource/Pages/CreateOrder.php

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
        $user = auth()->user();
        if ($user?->hasRole('seller')) {
            $data['seller_id'] = $user->id;
            $data['branch_id'] = $data['branch_id'] ?? $user->branch_id;
        }

        $code = $data['currency'] ?? 'USD';
        $rate = \App\Models\CurrencyRate::getRate($code);
        $data['exchange_rate'] = $rate;

        $subtotalUsd = collect($data['items'] ?? [])
            ->sum(fn ($i) => (float)($i['qty'] ?? 0) * (float)($i['unit_price'] ?? 0));
        $discountUsd = (float)($data['discount'] ?? 0);
        $shippingUsd = (float)($data['shipping'] ?? 0);

        $data['subtotal'] = round($subtotalUsd, 2);
        $data['discount'] = round($discountUsd, 2);
        $data['shipping'] = round($shippingUsd, 2);
        $data['total']    = round(max(0, $subtotalUsd - $discountUsd + $shippingUsd), 2);

        // 🔐 Important: don’t persist payment fields on proforma
        if (($data['type'] ?? 'proforma') === 'proforma') {
            $data['customer_id'] = $data['customer_id'] ?? null; // proforma can be without customer
            unset($data['payment_status'], $data['paid_amount']);
        } else {
            // Normalize paid_amount based on payment_status
            $total = (float)($data['total'] ?? 0);
            $status = $data['payment_status'] ?? 'unpaid';
            $paid = (float)($data['paid_amount'] ?? 0);

            $data['paid_amount'] = match ($status) {
                'paid'            => $total,
                'unpaid', 'debt'  => 0.0,
                'partially_paid'  => min(max($paid, 0.0), $total),
                default           => 0.0,
            };
        }

        return $data;
    }


    protected function afterCreate(): void
    {
        $order = $this->record->loadMissing('items');
        $branchId = (int) $order->branch_id;

        foreach ($order->items as $item) {
            self::adjustBranchStock((int)$item->product_id, $branchId, -1 * (int)$item->qty);
        }

        // NEW: write / update wallet rows (debit + initial credit)
        $order->syncWallet();
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
