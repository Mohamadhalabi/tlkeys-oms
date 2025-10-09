<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder; // 👈 import this

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payments');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->label(__('Amount (USD)'))
                ->numeric()->minValue(0.01)->step('0.01')->required(),
            Forms\Components\TextInput::make('note')
                ->label(__('Note'))
                ->maxLength(255)
                ->default(fn () => __('Payment for order ') . $this->getOwnerRecord()->code),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // ✅ type-hint the query
            ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'credit'))
            ->columns([
                Tables\Columns\TextColumn::make('amount')->label(__('Amount'))->money('USD', true),
                Tables\Columns\TextColumn::make('note')->label(__('Note'))->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label(__('Date'))->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $order = $this->getOwnerRecord();
                        $data['type']        = 'credit';
                        $data['order_id']    = $order->id;
                        $data['customer_id'] = $order->customer_id;
                        $data['note']        = $data['note'] ?: __('Payment for order ') . $order->code;
                        return $data;
                    })
                    ->after(fn () => $this->getOwnerRecord()->refresh()->syncWallet()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(fn () => $this->getOwnerRecord()->refresh()->syncWallet()),
                Tables\Actions\DeleteAction::make()
                    ->after(fn () => $this->getOwnerRecord()->refresh()->syncWallet()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
