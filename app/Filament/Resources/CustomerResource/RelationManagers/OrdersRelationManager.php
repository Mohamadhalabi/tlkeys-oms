<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\Order;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';
    protected static ?string $title = 'Orders';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Order #')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('payment_status')->label('Payment')->badge(),
                Tables\Columns\TextColumn::make('total')->label('Total (USD)')
                    ->state(fn (Order $r) => number_format($r->total, 2)),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([]) // no "Create" from here
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }
}
