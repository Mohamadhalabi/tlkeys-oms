<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action as NotificationAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['items']);

        $data = $this->normalizeOrderNumbers($data);

        $record->update($data);

        $this->applyWalletSideEffects($record->refresh());
        return $record;
    }

    private function normalizeOrderNumbers(array $data): array
    {
        foreach ([
            'subtotal' => 0,
            'discount' => 0,
            'shipping' => 0,
            'extra_fees_percent' => 0,
            'total' => 0,
            'exchange_rate' => 1,
            'paid_amount' => 0,
        ] as $key => $fallback) {
            $val = $data[$key] ?? $fallback;
            if ($val === '' || $val === null) $val = $fallback;
            $data[$key] = is_numeric($val) ? (float) $val : $fallback;
        }

        if (($data['type'] ?? 'proforma') === 'order') {
            $data['paid_amount'] = max(0, min((float)$data['paid_amount'], (float)$data['total']));
            if (($data['payment_status'] ?? 'unpaid') === 'paid') {
                $data['paid_amount'] = (float) $data['total'];
            } elseif ($data['payment_status'] === 'unpaid') {
                $data['paid_amount'] = 0.0;
            } else {
                if ($data['paid_amount'] <= 0 || $data['paid_amount'] >= (float)$data['total']) {
                    $data['payment_status'] = ($data['paid_amount'] >= (float)$data['total']) ? 'paid' : 'unpaid';
                }
            }
        }

        return $data;
    }

    /**
     * Build repeater rows for the form. Also:
     *  - Order items by 'sort'
     *  - Eager-load products with only existing columns
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var \App\Models\Order $order */
        $order = $this->getRecord();

        // Ensure items are ordered by 'sort' and product is eager-loaded safely
        $order->loadMissing([
            'items' => fn ($q) => $q->orderBy('sort'),
            'items.product' => fn ($q) => $q->select('id', 'sku', 'image', 'price', 'sale_price'),
        ]);

        $rows = [];
        $i    = 1;
        $rate = (float) ($order->exchange_rate ?: 1);

        foreach ($order->items as $item) {
            $p = $item->product;

            // Base unit in USD (keep same logic but guard null product)
            $baseUsd = $rate > 0
                ? round(((float) $item->unit_price) / $rate, 6)
                : (float) ($p?->sale_price ?? $p?->price ?? 0);

            $rows[] = [
                'row_index'      => $i++,
                'product_id'     => $item->product_id,
                'qty'            => (float) $item->qty,
                'unit_price'     => (float) $item->unit_price,
                'line_total'     => (float) $item->line_total,
                'sku'            => $p?->sku,
                'thumb'          => \App\Filament\Resources\OrderResource::productThumbUrl($p?->image),
                'base_unit_usd'  => $baseUsd,
                'stock_info'     => null,
                'note'           => $item->note, // keep per-item note
            ];
        }

        $data['items'] = $rows;
        return $data;
    }

    private function applyWalletSideEffects(Order $order): void
    {
        if ($order->type !== 'order' || !$order->customer_id) {
            $tx = DB::table('wallet_transactions')->where('order_id', $order->id)->first();
            if ($tx) {
                DB::table('customers')->where('id', $order->customer_id)->increment('wallet_balance', (float) $tx->amount);
                DB::table('wallet_transactions')->where('id', $tx->id)->delete();
            }
            return;
        }

        $tx = DB::table('wallet_transactions')->where('order_id', $order->id)->first();

        $shouldCharge = in_array($order->payment_status, ['partial', 'paid'], true);
        $amountToCharge = ($order->payment_status === 'paid')
            ? (float) $order->total
            : (float) $order->paid_amount;

        if ($shouldCharge && $amountToCharge > 0) {
            if ($tx) {
                $delta = $amountToCharge - (float) $tx->amount;
                if ($delta !== 0.0) {
                    DB::table('customers')->where('id', $order->customer_id)->decrement('wallet_balance', $delta);
                }
                DB::table('wallet_transactions')->where('id', $tx->id)->update([
                    'customer_id' => $order->customer_id,
                    'type'        => 'debit',
                    'amount'      => $amountToCharge,
                    'note'        => 'Order ' . ($order->code ?? $order->id) . ' payment',
                    'updated_at'  => now(),
                ]);
            } else {
                DB::table('wallet_transactions')->insert([
                    'customer_id' => $order->customer_id,
                    'order_id'    => $order->id,
                    'type'        => 'debit',
                    'amount'      => $amountToCharge,
                    'note'        => 'Order ' . ($order->code ?? $order->id) . ' payment',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                DB::table('customers')->where('id', $order->customer_id)->decrement('wallet_balance', $amountToCharge);
            }
        } else {
            if ($tx) {
                DB::table('customers')->where('id', $order->customer_id)->increment('wallet_balance', (float) $tx->amount);
                DB::table('wallet_transactions')->where('id', $tx->id)->delete();
            }
        }
    }

    protected function afterSave(): void
    {
        $this->fillForm();

        $order = $this->record;

        Notification::make()
            ->title(__('Order saved'))
            ->success()
            ->actions([
                NotificationAction::make('download')
                    ->label(__('Download PDF'))
                    ->url(route('admin.orders.pdf', $order))
                    ->openUrlInNewTab(),
            ])
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('convert_to_order')
                ->label(__('Convert to Order'))
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn () => $this->record?->type === 'proforma')
                ->requiresConfirmation()
                ->action(function () {
                    /** @var Order $o */
                    $o = $this->record->refresh();
                    $o->update([
                        'type'            => 'order',
                        'status'          => 'pending',
                        'payment_status'  => 'unpaid',
                        'paid_amount'     => 0.0,
                    ]);
                    $tx = DB::table('wallet_transactions')->where('order_id', $o->id)->first();
                    if ($tx) {
                        DB::table('customers')->where('id', $o->customer_id)->increment('wallet_balance', (float) $tx->amount);
                        DB::table('wallet_transactions')->where('id', $tx->id)->delete();
                    }

                    Notification::make()->title(__('Converted to Order'))->success()->send();

                    $this->fillForm();
                }),

            Actions\Action::make('pdf')
                ->label(__('PDF'))
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn () => route('admin.orders.pdf', $this->record))
                ->openUrlInNewTab(),

            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->check()
            && auth()->user()->hasAnyRole(['Seller','seller'])
            && empty($this->record->seller_id)) {
            $data['seller_id'] = auth()->id();
        }
        return $data;
    }
}
