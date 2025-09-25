<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * Header actions shown on the edit page.
     */
    protected function getHeaderActions(): array
    {
        return [
            // Convert Proforma -> Order
            Actions\Action::make('convertToOrder')
                ->label('Convert to Order')
                ->visible(fn (): bool => $this->record instanceof Order && $this->record->type === 'proforma')
                ->action(function (): void {
                    /** @var Order $order */
                    $order = $this->record;
                    OrderService::convertToOrder($order);
                    OrderService::recalc($order);
                    $this->notify('success', 'Converted to Order.');
                    $this->refreshFormData();
                }),

            // Confirm Order (debit wallet + decrement stock)
            Actions\Action::make('confirmOrder')
                ->label('Confirm Order')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record instanceof Order && $this->record->type === 'order' && $this->record->status === 'draft')
                ->action(function (): void {
                    /** @var Order $order */
                    $order = $this->record;
                    OrderService::recalc($order);
                    OrderService::confirm($order);
                    $this->notify('success', 'Order confirmed.');
                    $this->refreshFormData();
                }),
        ];
    }

    /**
     * Force seller_id to logged-in user before saving.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['seller_id'] = auth()->id();   // always assign to current user
        return $data;
    }

    /**
     * After saving edits, make sure totals are correct.
     */
    protected function afterSave(): void
    {
        if ($this->record instanceof Order) {
            OrderService::recalc($this->record);
        }
    }
}
