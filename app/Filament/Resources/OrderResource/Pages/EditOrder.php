<?php
// app/Filament/Resources/OrderResource/Pages/EditOrder.php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\CurrencyRate;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // keep copy of original items for stock adjust
        $this->data['__original_items__'] = $this->record->items()
            ->get(['product_id','qty'])
            ->map(fn ($i) => ['product_id' => (int) $i->product_id, 'qty' => (int) $i->qty])
            ->toArray();

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

        if (($data['type'] ?? 'proforma') === 'proforma') {
            $data['customer_id'] = $data['customer_id'] ?? null;
            unset($data['payment_status'], $data['paid_amount']);
        } else {
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


    protected function afterSave(): void
    {
        // Stock diff
        $order    = $this->record->loadMissing('items');
        $branchId = (int) $order->branch_id;

        $old = collect($this->data['__original_items__'] ?? [])
            ->groupBy('product_id')->map(fn ($g) => (int) $g->sum('qty'));

        $new = $order->items
            ->groupBy('product_id')->map(fn ($g) => (int) $g->sum('qty'));

        $allProductIds = $old->keys()->merge($new->keys())->unique();

        foreach ($allProductIds as $pid) {
            $before   = (int) ($old[$pid] ?? 0);
            $after    = (int) ($new[$pid] ?? 0);
            $deltaQty = $after - $before;

            if ($deltaQty !== 0) {
                self::adjustBranchStock((int) $pid, $branchId, -1 * $deltaQty);
            }
        }

        // Financials safety
        $subtotalUsd = (float) $order->items->sum(fn ($i) => (float) $i->qty * (float) $i->unit_price);
        $discountUsd = (float) ($order->discount ?? 0);
        $shippingUsd = (float) ($order->shipping ?? 0);

        $order->subtotal = round($subtotalUsd, 2);
        $order->total    = round(max(0, $subtotalUsd - $discountUsd + $shippingUsd), 2);

        if (! $order->exchange_rate) {
            $order->exchange_rate = \App\Models\CurrencyRate::getRate($order->currency ?: 'USD');
        }

        $order->saveQuietly();

        // Wallet sync (only if order)
        $order->syncWallet();
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('convertToOrder')
                ->label(__('Convert to Order'))
                ->icon('heroicon-o-arrow-path')
                ->visible(fn () => $this->record->type === 'proforma')
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->type = 'order';
                    $this->record->payment_status = 'unpaid';
                    $this->record->paid_amount = 0;
                    $this->record->save();

                    $this->record->syncWallet();

                    Notification::make()
                        ->title(__('Converted to Order'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
