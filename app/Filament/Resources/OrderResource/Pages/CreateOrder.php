<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action as NotificationAction;
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

        // Snapshot exchange rate ONCE at creation (based on chosen currency)
        $code = $data['currency'] ?? 'USD';
        $rate = \App\Models\CurrencyRate::getRate($code);
        if ($rate <= 0) $rate = 1;
        $data['exchange_rate'] = $rate;

        // Totals in USD are canonical (percent already converted to USD into extra_fees by the form)
        $subtotalUsd = collect($data['items'] ?? [])
            ->sum(fn ($i) => (float)($i['qty'] ?? 0) * (float)($i['unit_price'] ?? 0));
        $discountUsd = (float)($data['discount'] ?? 0);
        $shippingUsd = (float)($data['shipping'] ?? 0);
        $extraFeesUsd= (float)($data['extra_fees'] ?? 0);

        $data['subtotal']   = round($subtotalUsd, 2);
        $data['discount']   = round($discountUsd, 2);
        $data['shipping']   = round($shippingUsd, 2);
        $data['extra_fees'] = round($extraFeesUsd, 2);
        $data['total']      = round(max(0, $subtotalUsd - $discountUsd + $shippingUsd + $extraFeesUsd), 2);

        if (($data['type'] ?? 'proforma') === 'proforma') {
            $data['customer_id'] = $data['customer_id'] ?? null;
            unset($data['payment_status'], $data['paid_amount']);
        } else {
            $total  = (float)($data['total'] ?? 0);
            $status = $data['payment_status'] ?? 'unpaid';
            $paid   = (float)($data['paid_amount'] ?? 0);

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
        DB::transaction(function () {
            $order    = $this->record->loadMissing('items');
            $branchId = (int) $order->branch_id;

            // Only deduct stock for 'order' type
            if ($order->type === 'order') {
                foreach ($order->items as $item) {
                    $pid = (int) $item->product_id;
                    $qty = (int) $item->qty;
                    if ($pid > 0 && $qty > 0) {
                        self::adjustBranchStock($pid, $branchId, -$qty); // deduct
                    }
                }
            }

            $order->syncWallet();
        });

        // ---- Persistent pop-up with actions ----
        $pdfUrl = route('admin.orders.pdf', $this->record) . '?lang=' . app()->getLocale();

        Notification::make()
            ->title(__('Order created'))
            ->body(__('What would you like to do next?'))
            ->success()
            ->persistent() // don’t auto-dismiss
            ->actions([
                NotificationAction::make('download_pdf')
                    ->label(__('Download PDF'))
                    ->url($pdfUrl, shouldOpenInNewTab: true)
                    ->button(),
                NotificationAction::make('close_popup')
                    ->label(__('Close'))
                    ->button()
                    ->close(),
            ])
            ->send();
    }

    /** same robust helper as in EditOrder */
    public static function adjustBranchStock(int $productId, int $branchId, int $delta): void
    {
        if ($branchId <= 0 || $productId <= 0 || $delta === 0) return;

        $initial = $delta;

        DB::statement(
            'INSERT INTO product_branch (product_id, branch_id, stock)
            VALUES (?, ?, GREATEST(0, ?))
            ON DUPLICATE KEY UPDATE
                stock = GREATEST(0, COALESCE(stock,0) + ?)',
            [$productId, $branchId, $initial, $delta]
        );
    }
}
