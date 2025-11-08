<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        if ($user?->hasAnyRole(['Seller','seller'])) {
            $data['seller_id'] = $user->id;
        } elseif (!$user?->hasAnyRole(['Admin','admin'])) {
            $data['seller_id'] = $user?->id;
        }
        if (($data['type'] ?? 'proforma') !== 'order') {
            $data['status']          = $data['status'] ?? 'draft';
            $data['payment_status']  = $data['payment_status'] ?? 'unpaid';
            $data['paid_amount']     = $data['paid_amount'] ?? 0;
        }

        return $this->normalizeOrderNumbers($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var \App\Models\Order $order */
        $order = static::getModel()::create($data);

        $this->applyWalletSideEffects($order);

        return $order;
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
            if ($data['payment_status'] === 'paid') {
                $data['paid_amount'] = (float)$data['total'];
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

    private function applyWalletSideEffects(Order $order): void
    {
        if ($order->type !== 'order' || !$order->customer_id) return;

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
        }
    }

    protected function afterCreate(): void
    {
        /** @var Order $order */
        $order = $this->record;

        Notification::make()
            ->title(__('orders.pdf_ready'))
            ->success()
            ->actions([
                NotificationAction::make('pdf')
                    ->label(__('orders.download_pdf'))
                    ->url(route('admin.orders.pdf', $order))
                    ->openUrlInNewTab(),
            ])
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
