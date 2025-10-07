<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class WalletTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'walletTransactions';
    protected static ?string $title = 'Wallet';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->options(['credit' => 'Credit (+)', 'debit' => 'Debit (-)'])
                ->default('credit')
                ->required(),
            Forms\Components\TextInput::make('amount')
                ->numeric()->step('0.01')->minValue(0.01)
                ->required(),
            Forms\Components\Select::make('order_id')
                ->label('Linked order (optional)')
                ->relationship('order', 'code')
                ->searchable()
                ->preload()
                ->nullable(),
            Forms\Components\TextInput::make('note')->maxLength(255)->nullable(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'credit' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn ($record) => ($record->type === 'debit' ? '-' : '+') . number_format($record->amount, 2)),
                Tables\Columns\TextColumn::make('order.code')->label('Order #')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('note')->wrap(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
